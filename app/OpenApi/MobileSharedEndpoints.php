<?php

namespace App\OpenApi;

use OpenApi\Annotations as OA;

/**
 * Mobile routes served by the Website's learner controllers
 * (routes/apis/mobile.php): same handlers and rules, mobile S2S headers.
 *
 * @OA\Get(
 *     path="/mobile/my-learning/completed",
 *     tags={"Mobile"},
 *     summary="📱 [MOBILE · S-05] Completed courses",
 *     description="📱 **MOBILE** · My Learning · Completed. A course is completed when the learner holds its active certificate (D-094); each row carries `certificate_id` so the app can open or download it (NEW2B-5793). Approved external training appears too (`kind = external`).",
 *     @OA\Parameter(ref="#/components/parameters/MobileAuthorization"),
 *     @OA\Parameter(ref="#/components/parameters/EmployeeCode"),
 *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
 *     @OA\Response(response=200, description="OK", @OA\JsonContent(
 *         @OA\Property(property="status", type="string", example="success"),
 *         @OA\Property(property="result", type="array", @OA\Items(type="object",
 *             @OA\Property(property="kind", type="string", enum={"course","external"}),
 *             @OA\Property(property="course_id", type="integer", nullable=true),
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="image", type="string", nullable=true),
 *             @OA\Property(property="course_type", type="string", nullable=true),
 *             @OA\Property(property="completed_at", type="string", format="date", nullable=true),
 *             @OA\Property(property="score_percent", type="integer", nullable=true),
 *             @OA\Property(property="certificate_id", type="integer", nullable=true),
 *             @OA\Property(property="certificate_earned", type="boolean"),
 *             @OA\Property(property="certificate_offered", type="boolean", example=true),
 *             @OA\Property(property="rate", type="integer", nullable=true)))))
 * )
 *
 * @OA\Get(
 *     path="/mobile/my-learning/summary",
 *     tags={"Mobile"},
 *     summary="📱 [MOBILE · S-05] My Learning counters",
 *     description="📱 **MOBILE** · The profile counters as on the Website (D-083): earned, in progress, required and not started.",
 *     @OA\Parameter(ref="#/components/parameters/MobileAuthorization"),
 *     @OA\Parameter(ref="#/components/parameters/EmployeeCode"),
 *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
 *     @OA\Response(response=200, description="OK")
 * )
 *
 * @OA\Get(
 *     path="/mobile/my-learning/qualifications/detail",
 *     tags={"Mobile"},
 *     summary="📱 [MOBILE · S-05] Qualifications with their courses",
 *     description="📱 **MOBILE** · Each qualification of the learner's job title with its percent (`QualificationHolding::percent`: granted = 100) and the courses that count towards it.",
 *     @OA\Parameter(ref="#/components/parameters/MobileAuthorization"),
 *     @OA\Parameter(ref="#/components/parameters/EmployeeCode"),
 *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
 *     @OA\Response(response=200, description="OK")
 * )
 *
 * @OA\Get(
 *     path="/mobile/my-learning/courses/{course}/evaluate",
 *     tags={"Mobile"},
 *     summary="📱 [MOBILE] Course evaluation form",
 *     description="📱 **MOBILE** · Figma 2286:168232. The templates put to the learner's course and cohort, one per step (`evaluation_categories`), each question with `type` (scale or stars), `is_required`, `scale_max` and the scale end labels (`scale_label_min` / `scale_label_max`, e.g. 'Unclear' / 'Very Clear'). Enrolled learners only; 404 when the course is not evaluated.",
 *     @OA\Parameter(ref="#/components/parameters/MobileAuthorization"),
 *     @OA\Parameter(ref="#/components/parameters/EmployeeCode"),
 *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
 *     @OA\Parameter(name="course", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\Response(response=200, description="OK", @OA\JsonContent(
 *         @OA\Property(property="status", type="string", example="success"),
 *         @OA\Property(property="result", type="object",
 *             @OA\Property(property="already_evaluated", type="boolean"),
 *             @OA\Property(property="instructors", type="array", @OA\Items(type="object",
 *                 @OA\Property(property="id", type="integer"), @OA\Property(property="name", type="string"))),
 *             @OA\Property(property="evaluation_categories", type="array", @OA\Items(type="object",
 *                 @OA\Property(property="id", type="integer"),
 *                 @OA\Property(property="name", type="string", example="Workplace Safety & Compliance"),
 *                 @OA\Property(property="questions", type="array", @OA\Items(type="object",
 *                     @OA\Property(property="id", type="integer"),
 *                     @OA\Property(property="type", type="string"),
 *                     @OA\Property(property="title", type="string"),
 *                     @OA\Property(property="is_required", type="boolean"),
 *                     @OA\Property(property="scale_max", type="integer", nullable=true),
 *                     @OA\Property(property="scale_label_min", type="string", nullable=true),
 *                     @OA\Property(property="scale_label_max", type="string", nullable=true)))))))),
 *     @OA\Response(response=403, description="Not enrolled"),
 *     @OA\Response(response=404, description="The course is not evaluated")
 * )
 *
 * @OA\Post(
 *     path="/mobile/my-learning/courses/{course}/evaluate",
 *     tags={"Mobile"},
 *     summary="📱 [MOBILE] Submit the course evaluation",
 *     description="📱 **MOBILE** · Once per learner and course. For an evaluated course this is the completion event: the certificate is issued when the configured rule is also met (D-091, D-094).",
 *     @OA\Parameter(ref="#/components/parameters/MobileAuthorization"),
 *     @OA\Parameter(ref="#/components/parameters/EmployeeCode"),
 *     @OA\Parameter(ref="#/components/parameters/AcceptLanguage"),
 *     @OA\Parameter(name="course", in="path", required=true, @OA\Schema(type="integer")),
 *     @OA\RequestBody(required=true, @OA\JsonContent(
 *         required={"instructor_id","questions"},
 *         @OA\Property(property="instructor_id", type="integer", description="One of the course's instructors."),
 *         @OA\Property(property="questions", type="object", description="question id => answer", example={"41": 4, "42": 5}))),
 *     @OA\Response(response=201, description="Recorded"),
 *     @OA\Response(response=403, description="Not enrolled"),
 *     @OA\Response(response=422, description="Missing required answers / already evaluated")
 * )
 */
class MobileSharedEndpoints {}
