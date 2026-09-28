<?php

namespace App\Services\Assignments;

use App\Http\Traits\HasFile;
use App\Models\CourseAssignmentQuestion;
use App\Models\UserCourseAssignmentAnswer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of file-type assignment questions (D-033, D-064):
 *   - the instructor's attachment on a question ("Assignment file");
 *   - the learner's upload as an answer ("Learner answer").
 *
 * Both live on the private disk under a server-generated name with a
 * content-derived extension (D-031, HasFile::uploadPrivateFile). The
 * original name is kept only for display and for the download's filename.
 * Nothing here decides who may read a file - the routes and controllers do.
 */
class AssignmentFileService
{
    use HasFile;

    private const DISK = 'private';

    public function storeQuestionAttachment(CourseAssignmentQuestion $question, UploadedFile $file): CourseAssignmentQuestion
    {
        $old = $question->attachment_path;
        $path = $this->uploadPrivateFile('AssignmentAttachment', $file);

        $question->forceFill([
            'attachment_path'        => $path,
            'attachment_name'        => $this->displayName($file),
            'attachment_size'        => (int) $file->getSize(),
            'attachment_uploaded_at' => now(),
        ])->save();

        $this->delete($old);

        return $question;
    }

    public function removeQuestionAttachment(CourseAssignmentQuestion $question): void
    {
        if ($question->attachment_path === null) {
            return;
        }

        $this->delete($question->attachment_path);
        $question->forceFill([
            'attachment_path' => null, 'attachment_name' => null,
            'attachment_size' => null, 'attachment_uploaded_at' => null,
        ])->save();
    }

    /**
     * Store a learner's file and return the answer columns to write. The old
     * file is deleted by the caller only after the new row is saved.
     *
     * @return array{file_path:string, file_name:string, file_size:int, file_uploaded_at:\Illuminate\Support\Carbon}
     */
    public function storeAnswerFile(UploadedFile $file): array
    {
        return [
            'file_path'        => $this->uploadPrivateFile('AssignmentAnswer', $file),
            'file_name'        => $this->displayName($file),
            'file_size'        => (int) $file->getSize(),
            'file_uploaded_at' => now(),
        ];
    }

    /** Delete the uploaded files of every answer to this question. */
    public function removeAnswerFiles(CourseAssignmentQuestion $question): void
    {
        UserCourseAssignmentAnswer::where('course_assignment_question_id', $question->id)
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->each(fn (string $path) => $this->delete($path));
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Download as an attachment, never inline, and never sniffed into an
     * executable type (D-031).
     */
    public function download(string $path, string $name): StreamedResponse
    {
        abort_unless(Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->download($path, $name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The client's filename, reduced to a safe display string: no path, no
     * control characters, at most 191 characters, never empty.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            $name = 'file.'.$this->safeExtensionFor($file);
        }

        return mb_substr($name, -191);
    }
}
