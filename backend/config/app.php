<?php
declare(strict_types=1);

use Sigep\Core\Env;

return [
    'name' => 'SIGEP',
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'America/Sao_Paulo'),

    // Valores iguais aos do front-end (sigep.html). Para novos cursos, basta incluir na lista.
    'cursos' => ['Desenvolvimento de Sistemas', 'Administração', 'Nutrição e Dietética', 'Enfermagem', 'Logística', 'Meio Ambiente', 'Recursos Humanos'],
    'anos' => ['1º Ano', '2º Ano', '3º Ano'],
    'periodos' => ['Manhã', 'Tarde', 'Noite'],
    'motivos' => ['Orientação de TCC', 'Dificuldade de aprendizagem', 'Acompanhamento pedagógico', 'Orientação vocacional', 'Questões disciplinares', 'Acompanhamento de frequência', 'Reunião de desempenho', 'Orientação de estágio', 'Outro'],

    // Horários oferecidos quando o orientador ainda não cadastrou disponibilidade (igual ao front).
    'horarios_padrao' => ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30'],
    'slot_minutos' => 30,
];
