<?php

// AI Assessment builder (D-113). `export.*` is printed in the exported
// documents in the assessment's content language, not the UI language.
return [
    'pasted_text' => 'Pasted text :n',
    'generation_started' => 'Generating questions. They will appear as they are written.',
    'question_regenerated' => 'Question regenerated.',
    'reordered' => 'Order saved.',
    'duplicated' => 'Assessment duplicated.',
    'copy' => 'copy',

    'errors' => [
        'not_configured'     => 'AI generation is not set up on this server yet. Ask the platform administrator to add the Gemini API key.',
        'daily_limit'        => 'You have used all your AI runs for today. Try again tomorrow.',
        'busy'               => 'Questions are already being generated for this assessment.',
        'no_sources'         => 'Add at least one source (a file or pasted text) first.',
        'no_counts'          => 'Choose how many questions of each type to generate.',
        'provider_auth'      => 'The AI service refused the server credentials. Ask the platform administrator to check the Gemini API key.',
        'provider_busy'      => 'The AI service is busy right now. Try again in a minute.',
        'provider_error'     => 'The AI service could not complete the request. Try again.',
        'blocked'            => 'The AI service declined this material. Check the sources and try again.',
        'invalid_output'     => 'The AI service returned questions that could not be used. Try again.',
        'source_unavailable' => 'A source file could not be sent to the AI service. Remove it and upload it again.',
        'timeout'            => 'Generation took too long and was stopped. Try again.',
        'superseded'         => 'Generation was replaced by a newer run.',
        'generating_locked'  => 'Wait until generation finishes.',
        'no_questions'       => 'There are no questions to export yet.',
    ],

    'source_errors' => [
        'type'          => 'Upload a PDF, Word (.docx) or PowerPoint (.pptx) file. The file content must match its extension.',
        'empty'         => 'No text was found in this file.',
        'unreadable'    => 'This file could not be read.',
        'too_large'     => 'This file is too large to read.',
        'too_many'      => 'An assessment can have up to :max sources.',
        'too_much_text' => 'The sources hold too much text for one assessment. Remove a source or paste a shorter text.',
    ],

    'validation' => [
        'questions_total' => 'An assessment can have up to :max questions in total.',
        'reorder_ids'     => 'Send every question of the assessment exactly once.',
        'answer_key'      => 'The correct answer must point to one of the options, and every blank needs an answer.',
    ],

    'export' => [
        'types' => ['pre' => 'Pre-Assessment', 'post' => 'Post-Assessment', 'quiz' => 'Quiz'],
        'variants' => ['trainer' => 'Trainer version', 'learner' => 'Learner version'],
        'question_types' => [
            'mcq' => 'Multiple choice', 'multi' => 'Multiple response', 'true_false' => 'True / False',
            'short' => 'Short answer', 'fill_blank' => 'Fill in the blank', 'matching' => 'Matching', 'scenario' => 'Scenario',
        ],
        'name' => 'Name',
        'date' => 'Date',
        'course' => 'Course',
        'questions_count' => 'Questions: :n',
        'select_all' => '(Select all that apply.)',
        'match_instruction' => 'Write the letter of the matching item next to each number.',
        'true' => 'True',
        'false' => 'False',
        'answer' => 'Answer',
        'answer_key' => 'Answer key',
        'explanation' => 'Explanation',
        'source' => 'Source',
        'bloom' => 'Bloom level',
        'difficulty' => 'Difficulty',
        'blooms' => ['remember' => 'Remember', 'understand' => 'Understand', 'apply' => 'Apply', 'analyze' => 'Analyze', 'evaluate' => 'Evaluate', 'create' => 'Create'],
        'difficulties' => ['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'],
        'columns' => [
            'number' => '#', 'type' => 'Type', 'context' => 'Scenario', 'question' => 'Question', 'options' => 'Options',
            'correct' => 'Correct answer', 'explanation' => 'Explanation', 'source' => 'Source', 'bloom' => 'Bloom level',
            'difficulty' => 'Difficulty', 'status' => 'Review status',
        ],
        'statuses' => ['pending' => 'Not reviewed', 'approved' => 'Approved', 'flagged' => 'Flagged'],
        'option_letters' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'],
    ],
];
