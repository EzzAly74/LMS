<?php

return [
    // Auth
    'login_success'       => 'تم تسجيل الدخول بنجاح.',
    'logout_success'      => 'تم تسجيل الخروج بنجاح.',
    'logout_all_success'  => 'تم تسجيل الخروج من جميع الأجهزة.',
    'invalid_credentials' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    'unauthenticated'     => 'غير مصادق عليه.',
    'forbidden'           => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    'token_expired'       => 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مجددًا.',

    // Mobile employee identity (token-less mobile auth)
    'mobile_employee_code_required' => 'رأس Employee-Code مطلوب.',
    'mobile_employee_not_found'     => 'لا يوجد موظف بهذا الكود.',

    // CRUD
    'retrieved'           => 'تم استرجاع البيانات بنجاح.',
    'created'             => 'تم الإنشاء بنجاح.',
    'updated'             => 'تم التحديث بنجاح.',
    'deleted'             => 'تم الحذف بنجاح.',
    'sent'                => 'تم إرسال الرسالة.',
    'not_found'           => 'المورد غير موجود.',
    'server_error'        => 'حدث خطأ غير متوقع.',

    // Business logic
    'exam_already_submitted'  => 'لقد أرسلت هذا الاختبار مسبقًا.',
    'already_evaluated'       => 'لقد أرسلت تقييمًا لهذه الدورة مسبقًا.',
    'evaluation_template_locked'       => 'أجاب المتعلمون على هذا النموذج بالفعل، لذا لم يعد تعديله ممكنًا.',
    'evaluation_cohort_mismatch'       => 'يجب أن تنتمي المجموعة إلى الدورة المختارة.',
    'evaluation_name_taken'            => 'يوجد نموذج تقييم آخر بهذا الاسم.',
    'evaluation_not_enrolled'          => 'أنت غير مسجل في هذه الدورة.',
    'evaluation_instructor_mismatch'   => 'هذا المحاضر لا يدرّس هذه الدورة.',
    'evaluation_answer_required'       => 'يرجى الإجابة عن هذا السؤال.',
    'evaluation_answer_range'          => 'اختر رقمًا صحيحًا من 1 إلى :max.',
    'evaluation_answer_too_long'       => 'هذه الإجابة طويلة جدًا.',
    'evaluation_question_not_asked'    => 'هذا السؤال ليس ضمن تقييمك.',
    'import_rejected'                  => 'لم يُستورد أي شيء. صحّح الصفوف المذكورة ثم ارفع الملف مرة أخرى.',
    'import_cell_required'             => 'هذه الخلية مطلوبة.',
    'import_cell_too_long'             => 'هذه القيمة طويلة جدًا.',
    'import_evaluation_type'           => 'يجب أن يكون النوع star أو scale.',
    'import_yes_no'                    => 'استخدم yes أو no.',
    'import_id_number'                 => 'استخدم الرقم التعريفي، أو اترك الخلية فارغة.',
    'import_scope_mismatch'            => 'يجب أن تحمل كل صفوف النموذج نفس course_id و cohort_id.',
    'import_type_mismatch'             => 'يجب أن تحمل كل صفوف النموذج نفس النوع (type).',
    'evaluation_single_type'           => 'يجب أن تستخدم كل أسئلة النموذج نوع السؤال نفسه.',
    'import_too_many_questions'        => 'يمكن أن يحتوي النموذج على :max سؤالًا كحد أقصى.',
    'import_course_not_evaluable'      => 'هذه الدورة غير موجودة أو لم يُفعَّل لها التقييم.',
    'import_duplicate_in_file'         => 'يوجد نموذج آخر في هذا الملف بنفس الاسم.',
    'form_already_submitted'  => 'لقد أرسلت هذا النموذج مسبقًا.',
    'attendance_complete'     => 'لقد حضرت جميع جلسات هذه الدورة مسبقًا.',
    'attendance_recorded'     => 'تم تسجيل الحضور بنجاح.',
    'rate_added'              => 'شكرًا لك! تم تسجيل تقييمك.',
    'validation_failed'       => 'البيانات المدخلة غير صالحة.',
    'conflict'                => 'حدث تعارض مع الحالة الحالية للمورد.',
    'course_not_enrolled'     => 'أنت غير مسجل في هذه الدورة.',
    'course_not_evaluatable'  => 'هذه الدورة غير متاحة للتقييم.',
    'submission_file_type'    => 'نوع الملف غير مقبول. المسموح: PDF أو Word أو Excel أو PowerPoint أو نص أو CSV أو PNG أو JPEG أو ZIP.',
    'import_file_type'        => 'نوع الملف غير مقبول. ارفع ملف XLSX أو XLS أو CSV.',
    'import_unreadable'       => 'تعذّرت قراءة الملف. نزّل القالب واملأه ثم أعد الرفع.',
    'import_empty'            => 'الملف المرفوع لا يحتوي على صفوف.',
    'import_missing_columns'  => 'الملف تنقصه أعمدة مطلوبة: :columns.',
    'import_too_many_rows'    => 'عدد الصفوف كبير جدًا. استورد :max صف على الأكثر في المرة الواحدة.',
    'schedule_file_type'      => 'ارفع الجدول كملف XLS أو XLSX.',
    'schedule_date_format'    => 'استخدم تاريخًا مثل 2026-10-05.',
    'schedule_time_format'    => 'استخدم وقتًا بنظام 24 ساعة مثل 09:30.',
    'schedule_end_before_start'=> 'يجب أن يكون وقت الانتهاء بعد وقت البدء.',
    'schedule_overlap'        => 'تتداخل هذه الجلسة مع جلسة الصف :row.',
    'schedule_no_sessions'    => 'لا يحتوي الجدول على جلسات. املأ صفًا واحدًا على الأقل.',
    'schedule_session_title'  => 'الجلسة :n',
    'schedule_new_session_in_past' => 'يجب أن تبدأ الجلسة الجديدة في المستقبل. لا يمكن إضافة الجلسات المنعقدة أو تعديلها.',
    'schedule_held_session_locked' => 'بدأت هذه الجلسة بالفعل، لذا لا يمكن تعديلها.',
    'schedule_overlap_existing'    => 'تتداخل هذه الجلسة مع جلسة الدفعة يوم :date من :from إلى :to.',
    'schedule_nothing_new'         => 'لا يحتوي الملف على جلسات جديدة. أضف صفوفًا للجلسات الجديدة، أو اترك الملف لتغيير الاسم أو السعة فقط.',
    'cohort_capacity_below_enrolled' => 'لا يمكن أن تقل السعة عن :count متعلمًا مسجلين بالفعل.',
    'qualification_name_taken' => 'يوجد مؤهل آخر بهذا الاسم.',
    'import_qualification_duplicate_in_file' => 'يوجد صف آخر في هذا الملف بنفس اسم المؤهل.',
    'import_too_many_learners' => 'يمكن منح المؤهل لـ :max متعلم كحد أقصى في الصف الواحد.',
    'import_unknown_job_title' => 'لا يوجد مسمى وظيفي باسم ":name".',
    'import_ambiguous_job_title' => 'يوجد أكثر من مسمى وظيفي باسم ":name".',
    'import_unknown_employee' => 'لا يوجد متعلم بالرقم الوظيفي ":id".',
    'import_ambiguous_employee' => 'يوجد أكثر من متعلم بالرقم الوظيفي ":id".',
    'external_training_not_pending' => 'تم البت في هذا الطلب بالفعل، لذا لم يعد تعديله ممكنًا.',
    'external_training_not_decided' => 'يمكن إعادة فتح الطلب المقبول أو المرفوض فقط.',
    'external_training_grant_note' => 'طلب تدريب خارجي مقبول رقم :id.',
    'external_training_hours_step' => 'أدخل عدد الساعات بخطوات مقدارها 0.5.',
    'external_training_reason_required' => 'اكتب سبب رفض هذا الطلب.',

    // Certificates (first-class entity)
    'certificate_issued'      => 'تم إصدار الشهادة بنجاح.',
    'certificate_revoked'     => 'تم إلغاء الشهادة بنجاح.',
    'certificate_not_found'   => 'الشهادة غير موجودة.',

    // حالة الشهادة (شارة "على المسار الصحيح / في خطر" للمتدرب)
    'certificate_status' => [
        'blocked_attendance' => 'لم تستوفِ الحد الأدنى المطلوب لنسبة الحضور في هذه الدورة.',
        'blocked_score'      => 'لم تحقق الحد الأدنى المطلوب للدرجة في هذه الدورة.',
        'blocked_both'       => 'لم تستوفِ الحد الأدنى المطلوب لنسبة الحضور والدرجة في هذه الدورة.',
    ],

    // الشريط الجانبي لمشغّل الدورة — تسميات المجموعات الاحتياطية
    'course_player' => [
        'general_content'   => 'محتوى الدورة',
        'assessments_group' => 'التقييمات',
        'week'              => 'الأسبوع :number',
    ],

    // اختبارات وواجبات المتدرب الغنية (قائمة على الأسئلة)
    'quiz_not_found_for_course'        => 'هذا الاختبار غير متاح لهذه الدورة.',
    'quiz_already_submitted'           => 'لقد أرسلت هذا الاختبار مسبقًا.',
    'quiz_not_submitted'               => 'لم ترسل هذا الاختبار بعد.',
    'quiz_question_not_in_quiz'        => 'هذا السؤال لا ينتمي إلى هذا الاختبار.',
    'assignment_not_question_based'    => 'هذا الواجب لا يحتوي على أسئلة للإجابة — يرجى رفع ملف بدلاً من ذلك.',
    'assignment_already_submitted'     => 'لقد أرسلت هذا الواجب مسبقًا.',
    'assignment_not_submitted'         => 'لم ترسل هذا الواجب بعد.',
    'assignment_question_not_in_assignment' => 'هذا السؤال لا ينتمي إلى هذا الواجب.',
    'quiz_question_not_in_quiz' => 'هذا السؤال لا ينتمي إلى هذا الاختبار.',
    'assignment_question_not_file' => 'هذا السؤال لا يقبل ملفًا.',
    'assignment_file_locked' => 'تم تقييم هذه الإجابة، لذا لا يمكن استبدال ملفها.',
    'assignment_file_type' => 'ارفع ملف PDF أو Word أو Excel أو PowerPoint أو PNG أو JPG.',
    'assignment_file_size' => 'يجب ألا يزيد حجم الملف على 10 ميجابايت.',

    // لوحة التحكم — أداة رمز الحضور
    'passcode' => [
        'generated'         => 'تم إنشاء رمز الحضور.',
        'no_live_session'   => 'لا توجد جلسة مباشرة الآن. يمكن إنشاء رمز الحضور فقط أثناء انعقاد جلسة.',
        'session_started'   => 'تم بدء الجلسة وإنشاء رمز الحضور.',
        'session_title'     => 'جلسة مباشرة — :date',
        'cohort_unavailable' => 'لا يمكن بدء جلسة لهذه المجموعة (دورة غير صحيحة أو منتهية).',
        'session_ended'     => 'تم إنهاء الجلسة.',
    ],

    // الوارد — مراسلة المشرفين (مجموعات المستلمين)
    'inbox' => [
        'learners'   => 'المتدربون',
        'recipients' => 'المستلمون',
        'all_of'     => 'كل :group',
    ],

    // الموبايل — الأكاديمية والتسجيل (S-01 → S-04)
    'mobile' => [
        'academy_summary'             => 'تم استرجاع ملخّص الأكاديمية.',
        'academy_scopes'              => 'تم استرجاع تبويبات الأكاديمية.',
        'scope_all'                   => 'الكل',
        'scope_special'               => 'دورات تخصصية',
        'scope_general'               => 'دورات عامة',
        'academy_courses'             => 'تم استرجاع دورات الأكاديمية.',
        'academy_course_detail'       => 'تم استرجاع تفاصيل الدورة.',
        'academy_notify_me'           => 'سنقوم بإشعارك عند فتح باب التسجيل للدفعة القادمة.',
        'academy_course_unavailable'  => 'هذه الدورة لم تعد متاحة. ربما اكتملت الدفعة أو أُغلق التسجيل.',
        'enrolment_success'           => 'تم تأكيد مقعدك بنجاح.',
        'enrolment_cohort_full'       => 'فشل التسجيل — اكتملت هذه الدفعة الآن.',
        'enrolment_closed'            => 'تم إغلاق التسجيل لهذه الدفعة.',
        'enrolment_no_cohort'         => 'لا توجد دفعة قادمة مفتوحة للتسجيل.',
        'enrolment_already'           => 'أنت مسجل بالفعل في هذه الدفعة. افتحها من قسم "تعلّمي".',

        // الموبايل — تعلّمي (S-05)
        'my_learning_overview'        => 'تم استرجاع نظرة عامة على تعلّمي.',
        'my_learning_courses'         => 'تم استرجاع دوراتي النشطة.',
        'my_learning_qualifications'  => 'تم استرجاع تقدّم المؤهلات.',
        'my_learning_certificates'    => 'تم استرجاع الشهادات.',

        // الموبايل — الحضور (S-06)
        'attendance_marked'           => 'تم تسجيل حضورك بنجاح.',
        'attendance_invalid_code'     => 'هذا الكود غير صحيح. تحقّق من المدرّب وحاول مرة أخرى.',
        'attendance_expired_code'     => 'انتهت صلاحية هذا الكود. اطلب من المدرّب إصدار كود جديد.',
        'attendance_no_open_window'   => 'لا توجد نافذة حضور مفتوحة لهذه الدورة الآن.',
        'attendance_already_marked'   => 'لقد سجّلت حضورك لهذه الجلسة بالفعل.',
        'attendance_session_active'   => 'تم استرجاع الجلسة النشطة.',
        'attendance_no_session'       => 'لا توجد جلسة مباشرة أو حضورية مجدولة لك اليوم.',

        // الموبايل — الشهادات (S-07)
        'certificate_download_ready'  => 'الشهادة جاهزة للتنزيل.',
        'certificate_not_found'       => 'لم تُصدَر شهادة لهذه الدورة بعد.',
    ],

    // إشعارات النظام التلقائية (المدرب / المشرف)
    'notifications' => [
        'external_training_submitted_title' => 'طلب تدريب خارجي جديد',
        'external_training_submitted_body' => 'قدّم :learner طلب ":title" للمراجعة.',
        'external_training_approved_title' => 'تم قبول التدريب الخارجي',
        'external_training_approved_body' => 'تم قبول تدريبك الخارجي ":title" وإضافته إلى سجل تدريبك.',
        'external_training_rejected_title' => 'لم يُقبل التدريب الخارجي',
        'external_training_rejected_body' => 'لم يُقبل تدريبك الخارجي ":title". السبب: :reason',
        'evaluation_dropped_instructor_title'    => 'تحديث تقييم الدورة',
        'evaluation_dropped_instructor_body'     => 'انخفض تقييم دورتك ":course" إلى :score.',
        'evaluation_dropped_admin_title'         => 'تحديث تقييم الدورة',
        'evaluation_dropped_admin_body'          => 'انخفض تقييم الدورة ":course" إلى :score.',
        'pending_grade_title'                 => 'مطلوب تصحيح يدوي',
        'pending_grade_body'                  => 'قام الطالب :student بتسليم ":title". يتطلب الأمر تصحيحًا يدويًا.',
        'assignment_completed_title'          => 'تم إكمال الواجب',
        'assignment_completed_single_body'    => 'أكمل :student الواجب ":title".',
        'assignment_completed_multiple_body'  => 'أكمل :student و :count آخرين الواجب ":title".',
        'course_assigned_title'               => 'تم تعيين دورة جديدة',
        'course_assigned_body'                => 'تم تعيين دورة جديدة لك: ":course".',
        'cohort_created_title'                => 'تمت إضافة دفعة جديدة',
        'cohort_created_body'                 => 'تمت إضافة دفعة جديدة ":cohort" إلى الدورة ":course".',
    ],
];
