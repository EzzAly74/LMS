<?php

use App\Http\Controllers\apis\CourseExamController;
use App\Http\Controllers\apis\CourseLectureController;
use App\Http\Controllers\apis\CourseSectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Course Content Routes — /api/v1/courses/{course}/sections|lectures|exams
|--------------------------------------------------------------------------
*/

Route::middleware(['auth.user', 'course.scope'])->group(function () {

    // Sections — read
    Route::get('courses/{course}/sections', [CourseSectionController::class, 'index']);

    // Lectures — read (grouped by section)
    Route::get('courses/{course}/lectures', [CourseLectureController::class, 'index']);

    // Modules — flat list (powers admin "Content" tab in course detail)
    Route::get('courses/{course}/modules',  [CourseLectureController::class, 'indexFlat']);

    // Exams — read
    Route::get('courses/{course}/exams',         [CourseExamController::class, 'index']);
    Route::get('courses/{course}/exams/{exam}',  [CourseExamController::class, 'show']);
});

Route::middleware(['auth.user', 'role:Admin', 'section:courses'])->group(function () {

    // Content tab: modules, quizzes and assignments as one list (D-109, Figma 342:11813).
    Route::get('courses/{course}/content', [CourseLectureController::class, 'contentList']);

    // Sections — write
    Route::post('courses/{course}/sections',               [CourseSectionController::class, 'store'])->ability('edit');
    Route::post('courses/{course}/sections/sync',          [CourseSectionController::class, 'sync'])->ability('edit');
    // New Cohort with its schedule (Figma 2393:123167 / 2393:122292).
    Route::get('courses/{course}/sections/schedule-template', [CourseSectionController::class, 'scheduleTemplate'])->middleware('throttle:10,1');
    Route::post('courses/{course}/sections/scheduled',     [CourseSectionController::class, 'storeWithSchedule'])->ability('edit')->middleware('throttle:10,1');
    // Edit Cohort in the same dialog: its schedule as a sheet, and the upload that adds only new sessions.
    Route::get('courses/{course}/sections/{section}/schedule-template', [CourseSectionController::class, 'sectionScheduleTemplate'])->middleware('throttle:10,1');
    Route::post('courses/{course}/sections/{section}/scheduled',        [CourseSectionController::class, 'updateWithSchedule'])->ability('edit')->middleware('throttle:10,1');
    Route::put('courses/{course}/sections/{section}',      [CourseSectionController::class, 'update']);
    Route::delete('courses/{course}/sections/{section}',   [CourseSectionController::class, 'destroy'])->ability('edit');

    // Lectures — write
    // Module files in parts, any size up to 500 MB (D-111).
    Route::post('courses/{course}/lectures/uploads', [\App\Http\Controllers\apis\LectureUploadController::class, 'start'])->ability('edit')->middleware('throttle:30,1');
    Route::get('courses/{course}/lectures/uploads/{upload}', [\App\Http\Controllers\apis\LectureUploadController::class, 'status'])->whereUuid('upload');
    Route::put('courses/{course}/lectures/uploads/{upload}', [\App\Http\Controllers\apis\LectureUploadController::class, 'append'])->ability('edit')->whereUuid('upload')->middleware('throttle:600,1');
    Route::post('courses/{course}/lectures/uploads/{upload}/complete', [\App\Http\Controllers\apis\LectureUploadController::class, 'complete'])->ability('edit')->whereUuid('upload')->middleware('throttle:30,1');

    // NB: /lectures/upload must come BEFORE /{lecture} to avoid route binding.
    Route::post('courses/{course}/lectures/upload',        [CourseLectureController::class, 'uploadFile'])->ability('edit');
    Route::post('courses/{course}/lectures',               [CourseLectureController::class, 'store'])->ability('edit');
    Route::put('courses/{course}/lectures/{lecture}',      [CourseLectureController::class, 'update']);
    Route::delete('courses/{course}/lectures/{lecture}',   [CourseLectureController::class, 'destroy'])->ability('edit');

    // Exams — write
    Route::post('courses/{course}/exams',                  [CourseExamController::class, 'store'])->ability('edit');
    Route::put('courses/{course}/exams/{exam}',            [CourseExamController::class, 'update']);
    Route::delete('courses/{course}/exams/{exam}',         [CourseExamController::class, 'destroy'])->ability('edit');
});
