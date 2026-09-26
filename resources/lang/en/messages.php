<?php

return [
    // Auth
    'login_success'       => 'Logged in successfully.',
    'logout_success'      => 'Logged out successfully.',
    'logout_all_success'  => 'Logged out from all devices.',
    'invalid_credentials' => 'Invalid email or password.',
    'unauthenticated'     => 'Unauthenticated.',
    'forbidden'           => 'You do not have permission to perform this action.',
    'token_expired'       => 'Your session has expired. Please log in again.',

    // Mobile employee identity (token-less mobile auth)
    'mobile_employee_code_required' => 'Employee-Code header is required.',
    'mobile_employee_not_found'     => 'No employee found with the supplied code.',

    // CRUD
    'retrieved'           => 'Data retrieved successfully.',
    'created'             => 'Created successfully.',
    'updated'             => 'Updated successfully.',
    'deleted'             => 'Deleted successfully.',
    'sent'                => 'Message sent.',
    'not_found'           => 'Resource not found.',
    'server_error'        => 'An unexpected error occurred.',

    // Business logic
    'exam_already_submitted'  => 'You have already submitted this exam.',
    'already_evaluated'       => 'You have already submitted an evaluation for this course.',
    'evaluation_template_locked'       => 'Learners have already answered this template, so it can no longer be edited.',
    'evaluation_cohort_mismatch'       => 'The cohort must belong to the selected course.',
    'evaluation_name_taken'            => 'Another evaluation template already has this name.',
    'evaluation_not_enrolled'          => 'You are not enrolled in this course.',
    'evaluation_instructor_mismatch'   => 'This instructor does not teach this course.',
    'evaluation_answer_required'       => 'Please answer this question.',
    'evaluation_answer_range'          => 'Choose a whole number from 1 to :max.',
    'evaluation_answer_too_long'       => 'This answer is too long.',
    'evaluation_question_not_asked'    => 'This question is not part of your evaluation.',
    'import_rejected'                  => 'Nothing was imported. Fix the rows listed and upload the file again.',
    'import_cell_required'             => 'This cell is required.',
    'import_cell_too_long'             => 'This value is too long.',
    'import_evaluation_type'           => 'Type must be star or scale.',
    'import_yes_no'                    => 'Use yes or no.',
    'import_id_number'                 => 'Use the numeric id, or leave it empty.',
    'import_scope_mismatch'            => 'Every row of a template must have the same course_id and cohort_id.',
    'import_too_many_questions'        => 'A template can have at most :max questions.',
    'import_course_not_evaluable'      => 'This course does not exist or does not have evaluation enabled.',
    'import_duplicate_in_file'         => 'Another template in this file has the same name.',
    'form_already_submitted'  => 'You have already submitted this form.',
    'attendance_complete'     => 'You have already attended all sessions for this course.',
    'attendance_recorded'     => 'Attendance recorded successfully.',
    'rate_added'              => 'Thanks! Your feedback has been recorded.',
    'validation_failed'       => 'The given data was invalid.',
    'conflict'                => 'A conflict occurred with the current state of the resource.',
    'course_not_enrolled'     => 'You are not enrolled in this course.',
    'course_not_evaluatable'  => 'This course is not available for evaluation.',
    'submission_file_type'    => 'That file type is not accepted. Allowed: PDF, Word, Excel, PowerPoint, text, CSV, PNG, JPEG or ZIP.',
    'import_file_type'        => 'That file type is not accepted. Upload an XLSX, XLS or CSV file.',
    'import_unreadable'       => 'The file could not be read. Export the template and fill it in, then upload again.',
    'import_empty'            => 'The uploaded file has no rows.',
    'import_missing_columns'  => 'The file is missing required columns: :columns.',
    'import_too_many_rows'    => 'Too many rows. Import at most :max at a time.',
    'import_name_required'    => 'Both name_en and name_ar are required.',
    'import_unknown_job_titles' => 'Unknown job titles, left unassigned: :names.',

    // Certificates (first-class entity)
    'certificate_issued'      => 'Certificate issued successfully.',
    'certificate_revoked'     => 'Certificate revoked successfully.',
    'certificate_not_found'   => 'Certificate not found.',

    // Certificate status projection (learner-facing "on track / at risk" badge)
    'certificate_status' => [
        'blocked_attendance' => 'You did not meet the required attendance threshold for this course.',
        'blocked_score'      => 'Your score did not meet the minimum required for this course.',
        'blocked_both'       => 'You did not meet the required attendance and score thresholds for this course.',
    ],

    // Course player sidebar — fallback group labels when a lecture/assessment
    // has no section-based "Week N" grouping to fall back on.
    'course_player' => [
        'general_content'   => 'Course Content',
        'assessments_group'  => 'Assessments',
        'week'               => 'Week :number',
    ],

    // Learner rich quiz / assignment submission (question-based)
    'quiz_not_found_for_course'        => 'This quiz is not available for this course.',
    'quiz_already_submitted'           => 'You have already submitted this quiz.',
    'quiz_not_submitted'               => 'You have not submitted this quiz yet.',
    'quiz_question_not_in_quiz'        => 'This question does not belong to the quiz.',
    'assignment_not_question_based'    => 'This assignment does not have questions to answer — submit a file instead.',
    'assignment_already_submitted'     => 'You have already submitted this assignment.',
    'assignment_not_submitted'         => 'You have not submitted this assignment yet.',
    'assignment_question_not_in_assignment' => 'This question does not belong to the assignment.',

    // Dashboard — Session Passcode widget
    'passcode' => [
        'generated'         => 'Passcode generated.',
        'no_live_session'   => 'No live session right now. A passcode can only be generated while a session is in progress.',
        'session_started'   => 'Session started and passcode generated.',
        'session_title'     => 'Live Session — :date',
        'cohort_unavailable' => 'This cohort is not available to start a session (wrong course or already ended).',
        'session_ended'     => 'Session ended.',
    ],

    // Inbox — admin messaging (recipient groups)
    'inbox' => [
        'learners'   => 'Learners',
        'recipients' => 'Recipients',
        'all_of'     => 'All :group',
    ],

    // Mobile — Academy & Enrolment (S-01 → S-04)
    'mobile' => [
        'academy_summary'             => 'Academy summary retrieved.',
        'academy_scopes'              => 'Academy scopes retrieved.',
        'scope_all'                   => 'All',
        'scope_special'               => 'Special Courses',
        'scope_general'               => 'General Courses',
        'academy_courses'             => 'Academy courses retrieved.',
        'academy_course_detail'       => 'Course detail retrieved.',
        'academy_notify_me'           => 'We will notify you when the next cohort opens.',
        'academy_course_unavailable'  => 'This course is no longer available. The cohort may have filled up or enrolment may have closed.',
        'enrolment_success'           => 'You have a confirmed seat.',
        'enrolment_cohort_full'       => 'Enrolment failed — this cohort just filled up.',
        'enrolment_closed'            => 'Enrolment for this cohort has closed.',
        'enrolment_no_cohort'         => 'No upcoming cohort is open for enrolment.',
        'enrolment_already'           => 'You are already enrolled in this cohort. Open it from My Learning.',

        // Mobile — My Learning (S-05)
        'my_learning_overview'        => 'My Learning overview retrieved.',
        'my_learning_courses'         => 'My active courses retrieved.',
        'my_learning_qualifications'  => 'Qualifications progress retrieved.',
        'my_learning_certificates'    => 'Certificates retrieved.',

        // Mobile — Attendance (S-06)
        'attendance_marked'           => 'Your attendance has been recorded.',
        'attendance_invalid_code'     => 'That code doesn\'t match. Check with your instructor and try again.',
        'attendance_expired_code'     => 'This code has expired. Ask your instructor to reissue it.',
        'attendance_no_open_window'   => 'There is no open attendance window for this course right now.',
        'attendance_already_marked'   => 'You have already marked attendance for this session.',
        'attendance_session_active'   => 'Active session retrieved.',
        'attendance_no_session'       => 'No live or in-person session is scheduled for you today.',

        // Mobile — Certificates (S-07)
        'certificate_download_ready'  => 'Certificate download ready.',
        'certificate_not_found'       => 'No certificate has been issued for this course yet.',
    ],

    // Event-driven system notifications (Instructor / Admin)
    'notifications' => [
        'evaluation_dropped_instructor_title'    => 'Updated course Evaluation',
        'evaluation_dropped_instructor_body'     => 'The evaluation for your course ":course" has dropped to :score.',
        'evaluation_dropped_admin_title'         => 'Updated course Evaluation',
        'evaluation_dropped_admin_body'          => 'The course Evaluation for ":course" has dropped to :score.',
        'pending_grade_title'                 => 'Manual grading required',
        'pending_grade_body'                  => 'Student :student submitted ":title". Manual grading is required.',
        'assignment_completed_title'          => 'Assignment completed',
        'assignment_completed_single_body'    => ':student has completed the assignment ":title".',
        'assignment_completed_multiple_body'  => ':student and :count others have completed the assignment ":title".',
        'course_assigned_title'               => 'New course assigned',
        'course_assigned_body'                => 'New course has been assigned to you: ":course".',
        'cohort_created_title'                => 'New course cohort added',
        'cohort_created_body'                 => 'A new cohort ":cohort" has been added to ":course".',
    ],
];
