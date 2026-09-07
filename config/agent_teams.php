<?php

// Dated research baseline; refreshable through the admin's public OpenRouter catalog check.
return [
    'researched_at' => '2026-09-07T00:00:00Z',
    'source' => 'https://openrouter.ai/api/v1/models',
    'models' => [
        ['id' => 'cohere/north-mini-code:free', 'name' => 'North Mini Code Free', 'roles' => ['coding'], 'context_window' => 256000, 'input_per_million' => 0, 'output_per_million' => 0, 'data_policy' => 'Provider-Datennutzung vor Freigabe prüfen; Free-Angebot.', 'source' => 'https://cohere.com/blog/north-mini-code'],
        ['id' => 'poolside/laguna-xs-2.1:free', 'name' => 'Laguna XS 2.1 Free', 'roles' => ['coding'], 'context_window' => 262144, 'input_per_million' => 0, 'output_per_million' => 0, 'data_policy' => 'Free-Endpoint kann Ein- und Ausgaben speichern und für Training verwenden.', 'source' => 'https://openrouter.ai/poolside/laguna-xs-2.1:free'],
        ['id' => 'nvidia/nemotron-3.5-lightning:free', 'name' => 'Nemotron 3.5 Lightning Free', 'roles' => ['research', 'review'], 'context_window' => 1000000, 'input_per_million' => 0, 'output_per_million' => 0, 'data_policy' => 'Free-Endpoint kann Ein- und Ausgaben speichern und für Training verwenden.', 'source' => 'https://openrouter.ai/nvidia/nemotron-3.5-lightning:free'],
        ['id' => 'nvidia/nemotron-3-super-120b-a12b:free', 'name' => 'Nemotron 3 Super Free', 'roles' => ['review', 'research'], 'context_window' => 262144, 'input_per_million' => 0, 'output_per_million' => 0, 'data_policy' => 'Free-Endpoint kann Ein- und Ausgaben speichern und für Training verwenden.', 'source' => 'https://openrouter.ai/nvidia/nemotron-3-super-120b-a12b:free'],
        ['id' => 'deepseek/deepseek-v4-flash-0731', 'name' => 'DeepSeek V4 Flash 0731', 'roles' => ['planning'], 'context_window' => 1048576, 'input_per_million' => 0.14, 'output_per_million' => 0.28, 'data_policy' => 'Datennutzung hängt vom gewählten Provider-Endpoint ab; vor Freigabe prüfen.', 'source' => 'https://huggingface.co/deepseek-ai/DeepSeek-V4-Flash-0731'],
    ],
];
