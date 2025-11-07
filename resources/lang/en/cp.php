<?php

return [
    'title' => 'AI Translations',
    'button' => 'AI Translations',
    'item' => [
        'provider' => [
            'display' => 'Provider',
            'instructions' => 'You can enable providers by modifying your .env',
            'placeholder' => 'No provider',
        ],
        'model' => [
            'display' => 'Model',
            'instructions' => 'The name of the model for the provider you selected',
            'placeholder' => 'gpt-4o-mini',
        ],
        'instructions' => [
            'display' => 'Instructions',
            'instructions' => 'Here you can specify custom instructions for your own site',
            'placeholder' => 'Use a cordial and friendly tone and...',
        ],
    ],
];
