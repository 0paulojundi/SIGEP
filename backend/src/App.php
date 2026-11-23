<?php
declare(strict_types=1);

namespace Sigep;

use Sigep\Core\AgendaLockInterface;
use Sigep\Core\Config;
use Sigep\Core\FileAgendaLock;
use Sigep\Core\NullAgendaLock;
use Sigep\Core\Container;
use Sigep\Core\Env;
use Sigep\Core\Kernel;
use Sigep\Core\NativeSession;
use Sigep\Core\Router;
use Sigep\Core\SessionInterface;
use Sigep\Mail\LogMailer;
use Sigep\Mail\MailerInterface;
use Sigep\Mail\NativeMailer;
use Sigep\Repositories\Contracts as C;
use Sigep\Repositories\Store as St;
use Sigep\Seed\DemoData;
use Sigep\Services\PermissionService;
use Sigep\Storage\ArrayStore;
use Sigep\Storage\JsonFileStore;
use Sigep\Storage\Store;

/**
 * Monta a aplicação (contêiner de dependências).
 *
 * ESTE É O PONTO DE TROCA DA PERSISTÊNCIA: a tabela $repositorios liga cada interface de repositório
 * à implementação temporária (Store/*) e à futura implementação em banco (Pdo/*).
 * Com STORAGE_DRIVER=pdo o sistema passa a usar as classes Pdo — nenhuma regra de negócio muda.
 */
final class App
{
    /** interface => [implementação temporária, implementação PDO (TODO(DB))] */
    private const REPOSITORIOS = [
        C\UsuarioRepositoryInterface::class => [St\StoreUsuarioRepository::class, 'Sigep\Repositories\Pdo\PdoUsuarioRepository'],
        C\AlunoRepositoryInterface::class => [St\StoreAlunoRepository::class, 'Sigep\Repositories\Pdo\PdoAlunoRepository'],
        C\InstituicaoRepositoryInterface::class => [St\StoreInstituicaoRepository::class, 'Sigep\Repositories\Pdo\PdoInstituicaoRepository'],
        C\DisponibilidadeRepositoryInterface::class => [St\StoreDisponibilidadeRepository::class, 'Sigep\Repositories\Pdo\PdoDisponibilidadeRepository'],
        C\AgendamentoRepositoryInterface::class => [St\StoreAgendamentoRepository::class, 'Sigep\Repositories\Pdo\PdoAgendamentoRepository'],
        C\ReuniaoRepositoryInterface::class => [St\StoreReuniaoRepository::class, 'Sigep\Repositories\Pdo\PdoReuniaoRepository'],
        C\NotificacaoRepositoryInterface::class => [St\StoreNotificacaoRepository::class, 'Sigep\Repositories\Pdo\PdoNotificacaoRepository'],
        C\AuditoriaRepositoryInterface::class => [St\StoreAuditoriaRepository::class, 'Sigep\Repositories\Pdo\PdoAuditoriaRepository'],
        C\PasswordResetRepositoryInterface::class => [St\StorePasswordResetRepository::class, 'Sigep\Repositories\Pdo\PdoPasswordResetRepository'],
        C\LoginThrottleRepositoryInterface::class => [St\StoreLoginThrottleRepository::class, 'Sigep\Repositories\Pdo\PdoLoginThrottleRepository'],
    ];

    /** @param array{root?:string,store?:Store,session?:SessionInterface,mailer?:MailerInterface} $o */
    public static function create(array $o = []): Kernel
    {
        $c = self::container($o);
        $router = new Router();
        (require ($o['root'] ?? dirname(__DIR__)) . '/routes/api.php')($router);
        $c->instance(Router::class, $router);
        return new Kernel($c, $router, $o['root'] ?? dirname(__DIR__));
    }

    public static function container(array $o = []): Container
    {
        $root = $o['root'] ?? dirname(__DIR__);
        $cfg = require $root . '/config/app.php';
        date_default_timezone_set($cfg['timezone']);

        $c = new Container();
        $c->instance(Config::class, new Config($cfg));
        $c->bind(PermissionService::class, fn () => new PermissionService(require $root . '/config/permissions.php'));

        $driver = strtolower((string) Env::get('STORAGE_DRIVER', 'file'));
        if ($driver !== 'pdo') {
            $store = $o['store'] ?? ($driver === 'array' ? new ArrayStore() : new JsonFileStore($root . '/storage/data'));
            $c->instance(Store::class, $store);
        }
        foreach (self::REPOSITORIOS as $interface => [$temporario, $pdo]) {
            $c->bind($interface, function (Container $c) use ($driver, $temporario, $pdo) {
                if ($driver !== 'pdo') {
                    return $c->get($temporario);
                }
                if (!class_exists($pdo)) {
                    throw new \RuntimeException("TODO(DB): crie $pdo (veja README, seção \"Conectando o MySQL\").");
                }
                return $c->get($pdo);
            });
        }

        $c->bind(AgendaLockInterface::class, fn () => $o['lock'] ?? ($driver === 'array' ? new NullAgendaLock() : new FileAgendaLock($root . '/storage/locks/agenda.lock')));
        $c->bind(SessionInterface::class, fn () => $o['session'] ?? new NativeSession($root . '/storage/sessions', 'SIGEPSESS', Env::int('SESSION_ABSOLUTE_SECONDS', 28800)));
        $c->bind(MailerInterface::class, fn () => $o['mailer'] ?? (strtolower((string) Env::get('MAIL_DRIVER', 'log')) === 'native'
            ? new NativeMailer((string) Env::get('MAIL_FROM_ADDRESS', 'nao-responda@localhost'), (string) Env::get('MAIL_FROM_NAME', 'SIGEP'))
            : new LogMailer($root . '/storage/logs/mail.log')));

        if ($driver !== 'pdo' && Env::bool('SEED_DEMO', false) && !$c->get(Store::class)->all('usuarios')) {
            DemoData::semear($c->get(Store::class), (string) Env::get('DEMO_PASSWORD', ''));
        }
        return $c;
    }
}
