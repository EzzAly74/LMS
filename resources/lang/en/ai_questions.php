<?php

// "Generate with AI" in the quiz and assignment forms (D-113).
return [
    'started' => 'Generating questions. This usually takes under a minute.',
    'pasted_text' => 'Pasted text',

    'errors' => [
        'not_configured'     => 'AI generation is not set up on this server yet. Ask the platform administrator to add the Gemini API key.',
        'daily_limit'        => 'You have used all your AI generations for today. Try again tomorrow.',
        'busy'               => 'You already have questions being generated. Wait for them to finish.',
        'provider_auth'      => 'The AI service refused the server credentials. Ask the platform administrator to check the Gemini API key.',
        'provider_busy'      => 'The AI service is busy or its quota is used up. Try again later.',
        'provider_error'     => 'The AI service could not complete the request. Try again.',
        'blocked'            => 'The AI service declined this material. Check the sources and try again.',
        'invalid_output'     => 'The AI service returned questions that could not be used. Try again.',
        'source_unavailable' => 'A source file could not be sent to the AI service. Upload it again.',
        'timeout'            => 'Generation took too long and was stopped. Try again.',
    ],

    'source_errors' => [
        'type'          => '":name" is not a PDF, Word (.docx) or PowerPoint (.pptx) file, or its content does not match its extension.',
        'empty'         => 'No text was found in ":name".',
        'unreadable'    => '":name" could not be read.',
        'too_large'     => '":name" is too large to read.',
        'too_much_text' => 'The sources hold too much text for one generation. Use fewer or shorter sources.',
    ],

    'validation' => [
        'no_source' => 'Upload a file or paste at least 50 characters of course text.',
        'counts'    => 'Ask for between 1 and :max questions in total.',
    ],
];
