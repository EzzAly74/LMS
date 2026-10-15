<?php

namespace App\OpenApi\Schemas;

use OpenApi\Annotations as OA;

/**
 * Mobile response shapes (S-03 Course Details, Figma 2286:167907; Schedule,
 * Figma 2286:168110; S-05 My Learning). Field lists follow the resources in
 * app/Http/Resources/Mobile.
 *
 * @OA\Schema(
 *     schema="MobileContentItem",
 *     description="One row of the Course Content list: a module, a published quiz or a published assignment, in player order. Drafts never appear; quizzes and assignments made for specific cohorts appear only to learners of those cohorts.",
 *     @OA\Property(property="key", type="string", example="quiz-12", description="Unique across kinds: '<kind>-<id>'."),
 *     @OA\Property(property="kind", type="string", enum={"lecture","quiz","assignment"}),
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string", example="Lookup Functions Deep Dive"),
 *     @OA\Property(property="content_type", type="string", enum={"video","reading","document","quiz","assignment","session","live","other"}),
 *     @OA\Property(property="label_key", type="string", example="enums.unit_content_type.quiz"),
 *     @OA\Property(property="duration_minutes", type="integer", nullable=true, description="A module's length, a quiz's time allowed; null for assignments.")
 * )
 *
 * @OA\Schema(
 *     schema="MobileCohortSession",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string", example="Session 1", description="Generated 'Session N' titles in the reader's language, numbered by the schedule."),
 *     @OA\Property(property="session_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="time_from", type="string", example="09:00:00", nullable=true),
 *     @OA\Property(property="time_to", type="string", example="11:30:00", nullable=true),
 *     @OA\Property(property="location", type="string", nullable=true, example="Hybrid · HQ Auditorium"),
 *     @OA\Property(property="content_ids", type="array", @OA\Items(type="integer"), description="Ids of `units` this session covers (cohort schedule sheet)."),
 *     @OA\Property(property="contents", type="array", description="Everything this session covers in sheet order, quizzes and assignments included; `key` matches `content[].key`.",
 *         @OA\Items(type="object",
 *             @OA\Property(property="kind", type="string", enum={"lecture","quiz","assignment"}),
 *             @OA\Property(property="id", type="integer"),
 *             @OA\Property(property="key", type="string", example="quiz-5"))),
 *     @OA\Property(property="duration_minutes", type="integer", nullable=true, example=150),
 *     @OA\Property(property="status", type="string", enum={"completed","upcoming"}, nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="MobileCohortBlock",
 *     description="The Next cohort card and the Schedule screen. With sessions, the dates, count and status come from the session rows.",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string", example="Cohort C"),
 *     @OA\Property(property="round", type="integer", nullable=true, example=3, description="The cohort's place among the course's cohorts by start date ('Round 3, Cohort C')."),
 *     @OA\Property(property="effective_status", type="string", example="scheduled"),
 *     @OA\Property(property="start_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="end_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="sessions_count", type="integer", nullable=true, example=5),
 *     @OA\Property(property="capacity", type="integer", example=24),
 *     @OA\Property(property="enrolled_count", type="integer", example=10),
 *     @OA\Property(property="seats_left", type="integer", example=14),
 *     @OA\Property(property="is_full", type="boolean"),
 *     @OA\Property(property="enrolment_closes_at", type="string", format="date", nullable=true),
 *     @OA\Property(property="days_until_deadline", type="integer", nullable=true),
 *     @OA\Property(property="deadline_severity", type="string", nullable=true),
 *     @OA\Property(property="sessions", type="array", @OA\Items(ref="#/components/schemas/MobileCohortSession"))
 * )
 *
 * @OA\Schema(
 *     schema="MobileCourseDetail",
 *     description="S-03 Course Details. Every course issues a certificate (has_certificate is always true).",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="course_type", type="string", enum={"online","offline","hybrid","external_link"}),
 *     @OA\Property(property="level", type="string", nullable=true),
 *     @OA\Property(property="image", type="string", nullable=true),
 *     @OA\Property(property="has_certificate", type="boolean", example=true),
 *     @OA\Property(property="rating", type="object",
 *         @OA\Property(property="avg", type="number", example=4.8),
 *         @OA\Property(property="count", type="integer", example=96),
 *         @OA\Property(property="sentiment", type="string")),
 *     @OA\Property(property="qualifications", type="array", @OA\Items(type="object",
 *         @OA\Property(property="id", type="integer"), @OA\Property(property="name", type="string"))),
 *     @OA\Property(property="instructors", type="array", @OA\Items(type="object")),
 *     @OA\Property(property="units", type="array", @OA\Items(type="object"), description="Modules only (what sessions' content_ids point at)."),
 *     @OA\Property(property="content", type="array", @OA\Items(ref="#/components/schemas/MobileContentItem")),
 *     @OA\Property(property="content_count", type="integer", example=12, description="'View all 12 units'."),
 *     @OA\Property(property="cohorts", type="array", @OA\Items(ref="#/components/schemas/MobileCohortBlock")),
 *     @OA\Property(property="anchor_cohort", ref="#/components/schemas/MobileCohortBlock", nullable=true),
 *     @OA\Property(property="cta", type="object",
 *         @OA\Property(property="state", type="string", enum={"enrol_now","get_notified","enrolled_view_learning","unavailable","not_enrollable"}),
 *         @OA\Property(property="label_key", type="string"),
 *         @OA\Property(property="enabled", type="boolean"))
 * )
 *
 * @OA\Schema(
 *     schema="MobileActiveCourseCard",
 *     description="S-05 active course card. Progress is the Website's figure (cohort modules, else attendance of the cohort's sessions); attendance counts distinct sessions; the certificate projection uses the course's own rule when it has one, else Platform Config.",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="cohort", type="object", nullable=true,
 *         @OA\Property(property="id", type="integer"),
 *         @OA\Property(property="name", type="string"),
 *         @OA\Property(property="start_date", type="string", format="date", nullable=true),
 *         @OA\Property(property="end_date", type="string", format="date", nullable=true),
 *         @OA\Property(property="is_upcoming", type="boolean", nullable=true, description="Before the first session's day, as on the Website."),
 *         @OA\Property(property="schedule", type="object", nullable=true)),
 *     @OA\Property(property="progress", type="object",
 *         @OA\Property(property="percent", type="integer", example=40),
 *         @OA\Property(property="attended_sessions", type="integer"),
 *         @OA\Property(property="past_sessions", type="integer"),
 *         @OA\Property(property="total_sessions", type="integer"),
 *         @OA\Property(property="absences", type="integer")),
 *     @OA\Property(property="certificate_status", type="string", nullable=true, enum={"earned","on_track","at_risk","blocked"}),
 *     @OA\Property(property="certificate_projection", type="object", nullable=true),
 *     @OA\Property(property="isLive", type="boolean"),
 *     @OA\Property(property="live_session", type="object", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="MobileQualificationProgress",
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="total_courses", type="integer"),
 *     @OA\Property(property="completed_courses", type="integer"),
 *     @OA\Property(property="percent", type="integer", description="100 when granted (D-093)."),
 *     @OA\Property(property="granted", type="boolean", description="Held outright (granted, or approved external training).")
 * )
 */
class Mobile {}
