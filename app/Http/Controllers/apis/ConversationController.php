<?php

declare(strict_types=1);

namespace App\Http\Controllers\apis;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Instructor;
use App\Models\User;
use App\Services\Admin\CourseScope;
use App\Services\MessageService;
use App\Support\Audit\AuditTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canonical two-way messaging API — the SINGLE store shared by the learner
 * website widget and the admin/instructor dashboard inbox. Principal-agnostic:
 * `$request->user()` may be a User (learner), Admin, or Instructor, and
 * MessageService resolves all same-email identities. This is what unifies the
 * two previously-disconnected surfaces onto one conversation thread.
 */
final class ConversationController extends ApiController
{
    /** Compose recipient_type → model class. */
    private const TYPES = [
        'instructor' => Instructor::class,
        'admin'      => Admin::class,
        'learner'    => User::class,
        'user'       => User::class,
    ];

    public function __construct(
        private readonly MessageService $service,
        private readonly CourseScope $scope,
    ) {}

    /** GET conversations?role=all|instructors|admins|learners&tab=all|unread|received|sent */
    public function index(Request $request): JsonResponse
    {
        $conversations = $this->service->conversationsFor(
            $request->user(),
            $request->get('role'),
            (int) $request->get('per_page', 30),
            $request->get('tab'),
        );

        return $this->success(__('messages.retrieved'), $conversations->getCollection()->values());
    }

    /** GET conversations/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        $total = $this->service->conversationsFor($request->user(), null, 1000)
            ->getCollection()
            ->sum('unread_count');

        return $this->success(__('messages.retrieved'), ['count' => (int) $total]);
    }

    /** GET conversations/recipients */
    public function recipients(Request $request): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->recipientsFor($request->user()));
    }

    /** GET conversations/{conversation} */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->success(__('messages.retrieved'), $this->service->thread($request->user(), $conversation));
    }

    /** POST conversations/{conversation}/reply */
    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->service->reply($request->user(), $conversation, $data['body']);

        return $this->success(__('messages.sent'), $this->service->thread($request->user(), $conversation->fresh()));
    }

    /** POST conversations — start a single new conversation. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_type' => ['required', 'string', 'in:instructor,admin,learner,user'],
            'recipient_id'   => ['required', 'integer'],
            'course_id'      => ['nullable', 'integer'],
            'subject'        => ['nullable', 'string', 'max:191'],
            'body'           => ['required', 'string', 'max:5000'],
        ]);

        $type = self::TYPES[$data['recipient_type']];
        $principal = $request->user();

        // A Dashboard account limited to its own courses writes only to its
        // own learners (D-074).
        if ($type === User::class && ! $this->scope->allowsLearner($principal, (int) $data['recipient_id'])) {
            abort(404);
        }

        $message = $this->service->start(
            $principal,
            $type,
            (int) $data['recipient_id'],
            isset($data['course_id']) ? (int) $data['course_id'] : null,
            $data['body'],
            $data['subject'] ?? null,
        );

        // NEW2B-6109: messages sent from the Dashboard are in the audit log.
        if ($principal instanceof Admin) {
            AuditTrail::record('sent', Conversation::class, (int) $message->conversation_id,
                ($data['subject'] ?? '') !== '' ? (string) $data['subject'] : __('messages.audit_message'));
        }

        return $this->success(__('messages.sent'), ['conversation_id' => (int) $message->conversation_id]);
    }

    /**
     * POST conversations/bulk — fan a message out to many recipients (the
     * dashboard "send to selected learners / whole role" broadcast), each as
     * its own two-way conversation.
     */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject'            => ['nullable', 'string', 'max:191'],
            'body'               => ['required', 'string', 'max:5000'],
            'recipients'         => ['required', 'array', 'min:1'],
            'recipients.*.type'  => ['required', 'string', 'in:instructor,admin,learner,user'],
            'recipients.*.id'    => ['required', 'integer'],
        ]);

        $principal = $request->user();
        $recipients = collect($data['recipients'])
            ->map(fn ($r) => ['type' => self::TYPES[$r['type']], 'id' => (int) $r['id']]);

        // A Dashboard account limited to its own courses reaches only the
        // learners of those courses (D-074): one query for the whole list.
        if ($this->scope->isScoped($principal)) {
            $learnerIds = $recipients->where('type', User::class)->pluck('id')->all();
            $allowed = $learnerIds === [] ? [] : array_flip($this->scope
                ->constrainLearners(User::query()->whereKey($learnerIds), $principal)
                ->pluck('users.id')->map(fn ($id) => (int) $id)->all());
            $recipients = $recipients->filter(fn ($r) => $r['type'] !== User::class || isset($allowed[$r['id']]));
        }

        $count = $this->service->startMany(
            $principal,
            $recipients->values()->all(),
            $data['subject'] ?? null,
            $data['body'],
        );

        // NEW2B-6109: one audit row per broadcast, with how many it reached.
        if ($count > 0) {
            AuditTrail::record('sent', Conversation::class, null,
                (($data['subject'] ?? '') !== '' ? $data['subject'] : __('messages.audit_message'))
                .' ('.trans_choice('messages.audit_recipients', $count, ['count' => $count]).')');
        }

        return $this->success(__('messages.sent'), ['count' => $count]);
    }
}
