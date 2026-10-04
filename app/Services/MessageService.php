<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Instructor;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two-way messaging for the learner web (Figma frames 841-42746 / 841-43294).
 *
 * A conversation is a thread between polymorphic participants (User /
 * Instructor / Admin). The authenticated learner signs in as a User but may
 * be addressed under any same-email identity (the platform's cross-entity
 * convention — see NotificationInboxController), so the feed is scoped to all
 * of them.
 */
final class MessageService
{
    /** Role buckets for the All / Instructors / Admins tabs. */
    private const ROLE_BY_TYPE = [
        User::class       => 'learners',
        Instructor::class => 'instructors',
        Admin::class      => 'admins',
    ];

    /**
     * The learner's conversations, newest activity first, with counterpart,
     * last-message preview and unread count. Optionally filtered by the
     * counterpart's role ("instructors" | "admins").
     */
    public function conversationsFor(Model $principal, ?string $role, int $perPage, ?string $tab = null): LengthAwarePaginator
    {
        $identities = $this->identities($principal);

        // The role and tab filters run in SQL, before the page is taken. They
        // used to run on the page afterwards, so pages came back short and
        // the rest of the inbox could not be reached (NEW2B-5905).
        $query = Conversation::query()
            ->whereHas('participants', fn ($q) => $this->scopeToIdentities($q, $identities))
            ->with(['latestMessage', 'course:id,title', 'participants'])
            ->withCount(['messages as unread_count' => fn ($q) => $this->scopeUnread($q, $identities)]);

        $roleType = $role !== null ? array_search($role, self::ROLE_BY_TYPE, true) : false;
        if ($roleType !== false) {
            // The counterpart is the first participant that is not me (shape()).
            $query->where(
                ConversationParticipant::query()
                    ->select('participant_type')
                    ->whereColumn('conversation_participants.conversation_id', 'conversations.id')
                    ->whereNot(fn ($q) => $this->scopeToIdentities($q, $identities))
                    ->orderBy('id')
                    ->limit(1),
                $roleType,
            );
        }

        match ($tab) {
            'unread'   => $query->whereHas('messages', fn ($q) => $this->scopeUnread($q, $identities)),
            'received' => $query->whereHas('latestMessage', fn ($q) => $this->scopeSenderNotMine($q, $identities)),
            'sent'     => $query->whereHas('latestMessage', fn ($q) => $this->scopeSenderMine($q, $identities)),
            default    => null,
        };

        $paginator = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $people = $this->counterparts($paginator->getCollection(), $identities);

        $paginator->setCollection($paginator->getCollection()
            ->map(fn (Conversation $c) => $this->shape($c, $identities, null, $people))
            ->values());

        return $paginator;
    }

    /** Unread messages across every conversation of the principal: one query (the sidebar badge). */
    public function unreadTotal(Model $principal): int
    {
        $identities = $this->identities($principal);

        return Message::query()
            ->whereIn('conversation_id', ConversationParticipant::query()
                ->select('conversation_id')
                ->where(fn ($q) => $this->scopeToIdentities($q, $identities)))
            ->where(fn ($q) => $this->scopeUnread($q, $identities))
            ->count();
    }

    /**
     * Messages from someone else, newer than my last read of their thread
     * (all of them when I never opened it). The `messages` rows are matched
     * on their own conversation, so this works for a count, an exists and a
     * plain messages query alike.
     *
     * @param  array<int, array{0:class-string,1:int}>  $identities
     */
    private function scopeUnread($query, array $identities): void
    {
        $lastRead = ConversationParticipant::query()
            ->selectRaw('MAX(last_read_at)')
            ->whereColumn('conversation_participants.conversation_id', 'messages.conversation_id')
            ->where(fn ($q) => $this->scopeToIdentities($q, $identities))
            ->toBase();

        $query->where(fn ($q) => $this->scopeSenderNotMine($q, $identities))
            ->whereRaw(
                'messages.created_at > COALESCE(('.$lastRead->toSql().'), ?)',
                [...$lastRead->getBindings(), '1000-01-01 00:00:00'],
            );
    }

    /**
     * Name and image of every counterpart on a page: one query per
     * participant table instead of one per row.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @param  array<int, array{0:class-string,1:int}>  $identities
     * @return array<string, array{name:?string, image:?string}>  keyed "type:id"
     */
    private function counterparts(Collection $conversations, array $identities): array
    {
        $idsByType = [];
        foreach ($conversations as $conversation) {
            $row = $this->counterpartRow($conversation, $identities);
            if ($row !== null) {
                $idsByType[$row->participant_type][] = (int) $row->participant_id;
            }
        }

        $people = [];
        foreach ($idsByType as $type => $ids) {
            if (! in_array($type, [User::class, Instructor::class, Admin::class], true)) {
                continue;
            }
            foreach ($type::query()->whereKey(array_unique($ids))->get() as $model) {
                $people[$type.':'.$model->getKey()] = $this->identityFromModel($model);
            }
        }

        return $people;
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function counterpartRow(Conversation $conversation, array $identities): ?ConversationParticipant
    {
        return $conversation->participants->first(
            fn (ConversationParticipant $p) => ! $this->isMine($p->participant_type, (int) $p->participant_id, $identities),
        );
    }

    /**
     * Thread messages (oldest first) + mark the conversation read for the
     * learner. Throws if the learner isn't a participant.
     *
     * @return array{conversation: array<string,mixed>, messages: Collection<int, array<string,mixed>>}
     */
    public function thread(Model $principal, Conversation $conversation): array
    {
        $identities = $this->identities($principal);
        $participant = $this->participantRow($conversation, $identities);
        abort_if($participant === null, 403);

        $participant->update(['last_read_at' => now()]);

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (Message $m) => $this->shapeMessage($m, $identities));

        return [
            'conversation' => $this->shape($conversation->fresh(['latestMessage', 'course:id,title', 'participants']), $identities, null) ?? [],
            'messages'     => $messages,
        ];
    }

    /** Post a reply into an existing conversation the learner belongs to. */
    public function reply(Model $principal, Conversation $conversation, string $body): Message
    {
        $identities = $this->identities($principal);
        abort_if($this->participantRow($conversation, $identities) === null, 403);

        $message = DB::transaction(function () use ($conversation, $principal, $body) {
            $message = $conversation->messages()->create([
                'sender_type' => $principal::class,
                'sender_id'   => $principal->getKey(),
                'body'        => $body,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $this->broadcastMessage($message, $conversation);

        return $message;
    }

    /**
     * Can `$principal` (under any of its cross-entity identities) read/reply
     * to this conversation? Used by the broadcasting channel authorizer for
     * `conversation.{id}` — mirrors the check `thread()`/`reply()` make.
     */
    public function isParticipant(Model $principal, Conversation $conversation): bool
    {
        return $this->participantRow($conversation, $this->identities($principal)) !== null;
    }

    /**
     * Broadcast a just-created message to the conversation's live viewers
     * (`conversation.{id}`) and every participant's inbox/unread badge
     * (`identity.{type}.{id}`). Fire-and-forget: a broadcast failure (e.g.
     * Reverb not running locally) must never fail the send itself.
     */
    private function broadcastMessage(Message $message, Conversation $conversation): void
    {
        try {
            $sender = $this->resolveIdentity($message->sender_type, (int) $message->sender_id);

            // Expand every participant to ALL of their cross-entity
            // identities, exactly as `identities()` does for authorization.
            //
            // One person can hold rows in several tables under the same email
            // (the coaching instructor is also an admin). `participantRow()`
            // already treats those as one identity, so such a person can open
            // and reply to a thread while the participant row names a
            // *different* entity than the one they logged in as. Broadcasting
            // used the stored row verbatim, so the event went to
            // `identity.Instructor.1` while their dashboard was listening on
            // `identity.Admin.2` — authorized to take part, but never told
            // anything had happened, hence the manual refresh.
            $channels = $conversation->participants
                ->flatMap(function (ConversationParticipant $p) {
                    $model = $p->participant_type::query()->find($p->participant_id);

                    $identities = $model !== null
                        ? $this->identities($model)
                        : [[$p->participant_type, (int) $p->participant_id]];

                    return array_map(
                        static fn (array $identity) => sprintf(
                            'identity.%s.%d',
                            class_basename($identity[0]),
                            $identity[1],
                        ),
                        $identities,
                    );
                })
                ->unique()
                ->values()
                ->all();

            broadcast(new MessageSent(
                conversationId: (int) $conversation->id,
                channels: $channels,
                payload: [
                    'id'              => (int) $message->id,
                    'conversation_id' => (int) $conversation->id,
                    'body'            => $message->body,
                    'sender_type'     => class_basename($message->sender_type),
                    'sender_id'       => (int) $message->sender_id,
                    'sender_name'     => $sender['name'] ?? '—',
                    'created_at'      => optional($message->created_at)->toIso8601String(),
                    'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
                ],
            ))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Start a new conversation with a recipient (instructor/admin) and send
     * the first message. Idempotently reuses an existing 1:1 thread with the
     * same recipient + course so replies stay in one place.
     */
    public function start(Model $principal, string $recipientType, int $recipientId, ?int $courseId, string $body, ?string $subject = null): Message
    {
        abort_unless(in_array($recipientType, [Instructor::class, Admin::class, User::class], true), 422);
        $recipient = $recipientType::query()->find($recipientId);
        abort_if($recipient === null, 404);

        [$message, $conversation] = DB::transaction(function () use ($principal, $recipient, $recipientType, $recipientId, $courseId, $body, $subject) {
            $conversation = $this->findExisting($principal, $recipient, $courseId)
                ?? Conversation::create(['course_id' => $courseId, 'subject' => $subject, 'last_message_at' => now()]);

            $this->ensureParticipant($conversation, $principal::class, (int) $principal->getKey());
            $this->ensureParticipant($conversation, $recipientType, $recipientId);

            $message = $conversation->messages()->create([
                'sender_type' => $principal::class,
                'sender_id'   => $principal->getKey(),
                'body'        => $body,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return [$message, $conversation];
        });

        $this->broadcastMessage($message, $conversation->fresh(['participants']));

        return $message;
    }

    /**
     * Fan out one message into an individual conversation per recipient — the
     * dashboard broadcast (to selected learners / whole admin roles) mapped
     * onto the two-way store. Skips self, unknown ids, and duplicates.
     *
     * @param  array<int, array{type: class-string, id: int}>  $recipients
     * @return int  number of conversations messaged
     */
    public function startMany(Model $principal, array $recipients, ?string $subject, string $body): int
    {
        $identities = $this->identities($principal);

        // Set-based (NEW2B-5904): the old loop ran start() per recipient,
        // about 25 queries and a Reverb call each, so a send to every learner
        // took minutes. Now: a fixed few reads, one insert per new thread,
        // chunked inserts for the rest, and the live pushes after the response.
        $idsByType = [];
        foreach ($recipients as $r) {
            $type = $r['type'];
            $id = (int) $r['id'];
            if (! in_array($type, [Instructor::class, Admin::class, User::class], true)
                || $this->isMine($type, $id, $identities)) {
                continue; // unknown type, or myself
            }
            $idsByType[$type][$id] = true;
        }

        /** @var array<string, array{type: class-string, id: int, email: ?string}> $targets keyed "type:id", in request order */
        $targets = [];
        foreach ($idsByType as $type => $ids) {
            foreach ($type::query()->whereKey(array_keys($ids))->get(['id', 'email']) as $model) {
                $targets[$type.':'.$model->id] = ['type' => $type, 'id' => (int) $model->id, 'email' => $model->email];
            }
        }
        if ($targets === []) {
            return 0;
        }

        $theirIdentities = $this->identitiesByEmail(array_column($targets, 'email'));
        $existing = $this->myCourselessThreads($identities);

        $now = now();
        $sender = [$principal::class, (int) $principal->getKey()];
        $participants = [];
        $messages = [];
        /** @var array<int, array<int, array{0:class-string,1:int}>> $threadIdentities conversation id → both sides */
        $threadIdentities = [];

        DB::transaction(function () use ($targets, $theirIdentities, $existing, $identities, $subject, $body, $now, $sender, &$participants, &$messages, &$threadIdentities) {
            foreach ($targets as $key => $t) {
                // Same rule as findExisting(): any identity of theirs already in a
                // course-less thread of mine reuses that thread.
                $theirs = [[$t['type'], $t['id']], ...($t['email'] ? ($theirIdentities[strtolower($t['email'])] ?? []) : [])];
                $conversationId = null;
                foreach ($theirs as [$type, $id]) {
                    if (isset($existing[$type.':'.$id])) {
                        $conversationId = $existing[$type.':'.$id];
                        break;
                    }
                }
                $conversationId ??= (int) Conversation::query()->insertGetId([
                    'course_id' => null, 'subject' => $subject, 'last_message_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ([$sender, [$t['type'], $t['id']]] as [$type, $id]) {
                    $participants[] = ['conversation_id' => $conversationId, 'participant_type' => $type, 'participant_id' => $id,
                        'created_at' => $now, 'updated_at' => $now];
                }
                $messages[] = ['conversation_id' => $conversationId, 'sender_type' => $sender[0], 'sender_id' => $sender[1],
                    'body' => $body, 'created_at' => $now, 'updated_at' => $now];
                $threadIdentities[$conversationId] = array_values(array_unique([...$identities, ...$theirs], SORT_REGULAR));
            }

            foreach (array_chunk($participants, 500) as $chunk) {
                ConversationParticipant::query()->insertOrIgnore($chunk); // conv_participant_unique
            }
            foreach (array_chunk($messages, 500) as $chunk) {
                Message::query()->insert($chunk);
            }
            foreach (array_chunk(array_keys($threadIdentities), 500) as $ids) {
                Conversation::query()->whereKey($ids)->update(['last_message_at' => $now, 'updated_at' => $now]);
            }
        });

        $this->broadcastMany($principal, $threadIdentities, $body, $now);

        return count($targets);
    }

    /**
     * Every same-email account across the three participant tables, for many
     * emails at once (identities() for one principal).
     *
     * @param  array<int, ?string>  $emails
     * @return array<string, array<int, array{0:class-string,1:int}>>  lower-cased email → identities
     */
    private function identitiesByEmail(array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails)));
        $out = [];
        foreach (array_chunk($emails, 1000) as $chunk) {
            foreach ([User::class, Instructor::class, Admin::class] as $model) {
                foreach ($model::query()->whereIn('email', $chunk)->get(['id', 'email']) as $row) {
                    $out[strtolower((string) $row->email)][] = [$model, (int) $row->id];
                }
            }
        }

        return $out;
    }

    /**
     * The other participants of my course-less threads, oldest thread first.
     *
     * @param  array<int, array{0:class-string,1:int}>  $identities
     * @return array<string, int>  "type:id" → conversation id
     */
    private function myCourselessThreads(array $identities): array
    {
        $mine = ConversationParticipant::query()
            ->select('conversation_id')
            ->where(fn ($q) => $this->scopeToIdentities($q, $identities));

        $map = [];
        ConversationParticipant::query()
            ->join('conversations', 'conversations.id', '=', 'conversation_participants.conversation_id')
            ->whereNull('conversations.course_id')
            ->whereIn('conversation_participants.conversation_id', $mine)
            ->orderBy('conversation_participants.conversation_id')
            ->get(['conversation_participants.conversation_id', 'participant_type', 'participant_id'])
            ->each(function (ConversationParticipant $p) use (&$map, $identities) {
                if (! $this->isMine($p->participant_type, (int) $p->participant_id, $identities)) {
                    $map[$p->participant_type.':'.$p->participant_id] ??= (int) $p->conversation_id;
                }
            });

        return $map;
    }

    /**
     * One live push per thread of a broadcast, sent after the response so a
     * large send is not held up by Reverb. Same event and channels as
     * broadcastMessage().
     *
     * @param  array<int, array<int, array{0:class-string,1:int}>>  $threadIdentities
     */
    private function broadcastMany(Model $principal, array $threadIdentities, string $body, \DateTimeInterface $now): void
    {
        if ($threadIdentities === []) {
            return;
        }

        $messageIds = Message::query()
            ->whereIn('conversation_id', array_keys($threadIdentities))
            ->where('sender_type', $principal::class)
            ->where('sender_id', $principal->getKey())
            ->groupBy('conversation_id')
            ->selectRaw('conversation_id, MAX(id) as id')
            ->pluck('id', 'conversation_id');

        $senderName = $this->identityFromModel($principal)['name'] ?? '—';
        $at = \Illuminate\Support\Carbon::instance($now)->toIso8601String();
        $events = [];
        foreach ($threadIdentities as $conversationId => $people) {
            $events[] = new MessageSent(
                conversationId: $conversationId,
                channels: array_values(array_unique(array_map(
                    static fn (array $i) => sprintf('identity.%s.%d', class_basename($i[0]), $i[1]),
                    $people,
                ))),
                payload: [
                    'id'              => (int) ($messageIds[$conversationId] ?? 0),
                    'conversation_id' => $conversationId,
                    'body'            => $body,
                    'sender_type'     => class_basename($principal::class),
                    'sender_id'       => (int) $principal->getKey(),
                    'sender_name'     => $senderName,
                    'created_at'      => $at,
                    'last_message_at' => $at,
                ],
            );
        }

        // The X-Socket-ID of the sender is read now, while the request is current.
        foreach ($events as $event) {
            $event->dontBroadcastToCurrentUser();
        }

        \Illuminate\Support\defer(function () use ($events) {
            foreach ($events as $event) {
                try {
                    broadcast($event);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * People the current principal may start a conversation with, grouped:
     *   - a LEARNER (website)  → the instructors of the courses they're
     *     enrolled in (one row per instructor, de-duplicated) + every Super
     *     Admin (dashboard user with the Super Admin role / all permissions).
     *   - an ADMIN / INSTRUCTOR (dashboard) → all learners.
     *
     * Each row carries a `role` bucket (`instructors` | `admins` | `learners`)
     * so the picker can section them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recipientsFor(Model $principal): array
    {
        $locale = app()->getLocale();

        if ($principal instanceof User) {
            // Instructors of the learner's enrolled courses — one row each.
            $instructors = DB::table('users_courses as uc')
                ->join('courses_instructors as ci', 'ci.course_id', '=', 'uc.course_id')
                ->join('instructors as i', 'i.id', '=', 'ci.instructor_id')
                ->where('uc.user_id', $principal->id)
                ->distinct()
                ->get(['i.id', 'i.name', 'i.image'])
                ->map(fn ($r) => $this->recipientRow('instructor', 'instructors', (int) $r->id, (string) $r->name, $r->image, $locale));

            // Super Admins — dashboard users holding the all-permissions role.
            $superAdmins = Admin::query()
                ->whereHas('roles', fn ($q) => $q->whereIn(DB::raw('LOWER(name)'), ['superadmin', 'super-admin', 'super_admin']))
                ->get()
                ->map(fn (Admin $a) => $this->recipientRow('admin', 'admins', (int) $a->id, (string) ($a->name ?? ''), $a->image, $locale));

            return $instructors->concat($superAdmins)->unique(fn ($r) => $r['recipient_type'] . ':' . $r['recipient_id'])->values()->all();
        }

        if ($principal instanceof Admin || $principal instanceof Instructor) {
            // Dashboard compose → learners.
            return User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'name_en', 'name_ar', 'image'])
                ->map(fn (User $u) => $this->recipientRow('learner', 'learners', (int) $u->id, $u->getLocalizedName(), $u->image, $locale))
                ->all();
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function recipientRow(string $type, string $group, int $id, string $name, $image, string $locale): array
    {
        return [
            'recipient_type' => $type,   // instructor | admin | learner
            'recipient_id'   => $id,
            'name'           => $this->localize($name, $locale) ?: '—',
            'image'          => $image
                ? (str_starts_with((string) $image, 'http') ? $image : asset('storage/' . ltrim((string) $image, '/')))
                : null,
            'role'           => $group,  // grouping bucket for the picker
        ];
    }

    private function localize(string $json, string $locale): string
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return (string) ($decoded[$locale] ?? $decoded['en'] ?? $decoded['ar'] ?? '');
        }

        return $json;
    }

    /* ────────────────────────────────────────────────────────────── */

    /**
     * @param  array<int, array{0:class-string,1:int}>  $identities
     * @param  array<string, array{name:?string, image:?string}>|null  $people  preloaded counterparts (counterparts())
     */
    private function shape(Conversation $conversation, array $identities, ?string $roleFilter, ?array $people = null): ?array
    {
        // The counterpart = the first participant that isn't one of my identities.
        $counterpartRow = $this->counterpartRow($conversation, $identities);

        $role = $counterpartRow ? (self::ROLE_BY_TYPE[$counterpartRow->participant_type] ?? 'learners') : 'learners';
        if ($roleFilter !== null && $roleFilter !== 'all' && $role !== $roleFilter) {
            return null;
        }

        $counterpart = null;
        if ($counterpartRow) {
            $key = $counterpartRow->participant_type.':'.$counterpartRow->participant_id;
            $counterpart = $people !== null && array_key_exists($key, $people)
                ? $people[$key]
                : $this->resolveIdentity($counterpartRow->participant_type, (int) $counterpartRow->participant_id);
        }
        $last = $conversation->latestMessage;

        // The list loads it with withCount(); a single thread counts it here.
        $unread = $conversation->getAttribute('unread_count')
            ?? $conversation->messages()->where(fn ($q) => $this->scopeUnread($q, $identities))->count();

        return [
            'id'         => (int) $conversation->id,
            'subject'    => $conversation->subject,
            'course'     => $conversation->course ? [
                'id'    => (int) $conversation->course->id,
                'title' => $conversation->course->getTranslation('title', app()->getLocale()),
            ] : null,
            'counterpart' => [
                'name'  => $counterpart['name'] ?? '—',
                'image' => $counterpart['image'] ?? null,
                'role'  => $role,
            ],
            'last_message' => $last ? [
                'body'       => \Illuminate\Support\Str::limit($last->body, 90),
                'created_at' => optional($last->created_at)->toIso8601String(),
                'mine'       => $this->isMine($last->sender_type, (int) $last->sender_id, $identities),
            ] : null,
            'unread_count'    => (int) $unread,
            'last_message_at' => optional($conversation->last_message_at)->toIso8601String(),
        ];
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function shapeMessage(Message $m, array $identities): array
    {
        $sender = $this->resolveIdentity($m->sender_type, (int) $m->sender_id);

        return [
            'id'          => (int) $m->id,
            'body'        => $m->body,
            'mine'        => $this->isMine($m->sender_type, (int) $m->sender_id, $identities),
            'sender_name' => $sender['name'] ?? '—',
            'created_at'  => optional($m->created_at)->toIso8601String(),
        ];
    }

    /** @return array<int, array{0:class-string,1:int}> */
    private function identities(Model $principal): array
    {
        $identities = [[$principal::class, (int) $principal->getKey()]];

        $email = $principal->email ?? null;
        if ($email) {
            foreach ([User::class, Instructor::class, Admin::class] as $model) {
                foreach ($model::query()->where('email', $email)->pluck('id') as $id) {
                    $identities[] = [$model, (int) $id];
                }
            }
        }

        return array_values(array_unique($identities, SORT_REGULAR));
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function scopeToIdentities($query, array $identities): void
    {
        $query->where(function ($q) use ($identities) {
            foreach ($identities as [$type, $id]) {
                $q->orWhere(fn ($inner) => $inner->where('participant_type', $type)->where('participant_id', $id));
            }
        });
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function scopeSenderNotMine($query, array $identities): void
    {
        foreach ($identities as [$type, $id]) {
            $query->whereNot(fn ($q) => $q->where('sender_type', $type)->where('sender_id', $id));
        }
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function scopeSenderMine($query, array $identities): void
    {
        $query->where(function ($q) use ($identities) {
            foreach ($identities as [$type, $id]) {
                $q->orWhere(fn ($inner) => $inner->where('sender_type', $type)->where('sender_id', $id));
            }
        });
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function participantRow(Conversation $conversation, array $identities): ?ConversationParticipant
    {
        return $conversation->participants->first(
            fn (ConversationParticipant $p) => $this->isMine($p->participant_type, (int) $p->participant_id, $identities),
        ) ?? ConversationParticipant::where('conversation_id', $conversation->id)
            ->where(fn ($q) => $this->scopeToIdentities($q, $identities))
            ->first();
    }

    /** @param array<int, array{0:class-string,1:int}> $identities */
    private function isMine(string $type, int $id, array $identities): bool
    {
        foreach ($identities as [$t, $i]) {
            if ($t === $type && $i === $id) {
                return true;
            }
        }

        return false;
    }

    private function ensureParticipant(Conversation $conversation, string $type, int $id): void
    {
        ConversationParticipant::firstOrCreate([
            'conversation_id'  => $conversation->id,
            'participant_type' => $type,
            'participant_id'   => $id,
        ]);
    }

    /**
     * Match on the *identity sets* (cross-entity same-email accounts) of
     * both sides, not the exact type/id pair used to send this message.
     * The same physical person can hold both an Instructor and an Admin
     * account (see `identities()`); a dashboard reply sent under one of
     * those accounts must still land in the thread a message under the
     * other account started, or "New Message" forks a duplicate thread
     * for a person the learner is already talking to.
     */
    private function findExisting(Model $principal, Model $recipient, ?int $courseId): ?Conversation
    {
        $mine   = $this->identities($principal);
        $theirs = $this->identities($recipient);

        return Conversation::query()
            ->where('course_id', $courseId)
            ->whereHas('participants', fn ($q) => $this->scopeToIdentities($q, $mine))
            ->whereHas('participants', fn ($q) => $this->scopeToIdentities($q, $theirs))
            ->first();
    }

    /** @return array{name:?string, image:?string}|null */
    private function resolveIdentity(string $type, int $id): ?array
    {
        /** @var Model|null $model */
        $model = $type::query()->find($id);

        return $model === null ? null : $this->identityFromModel($model);
    }

    /** @return array{name:?string, image:?string} */
    private function identityFromModel(Model $model): array
    {
        // A learner's `name` is the HR Arabic name; the language columns come
        // first (NEW2B-5906). Instructor (and some other) `name` fields are
        // translatable JSON — localize so the UI never shows a raw
        // {"en":..,"ar":..} blob.
        $name = $model instanceof User ? ($model->getLocalizedName() ?: null) : ($model->name ?? null);

        return [
            'name'  => $name !== null ? $this->localize((string) $name, app()->getLocale()) : null,
            'image' => isset($model->image) && $model->image
                ? (str_starts_with((string) $model->image, 'http') ? $model->image : asset('storage/' . ltrim((string) $model->image, '/')))
                : null,
        ];
    }
}
