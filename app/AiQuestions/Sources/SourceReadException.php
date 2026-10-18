<?php

namespace App\AiQuestions\Sources;

use RuntimeException;

/** A source file that cannot be read; the message is a key under ai_assessments.source_errors. */
final class SourceReadException extends RuntimeException {}
