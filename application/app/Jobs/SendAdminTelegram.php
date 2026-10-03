<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\User;
use App\Support\PsychologistPages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendAdminTelegram implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public array $backoff = [60, 300];

    public function __construct(public string $event, public int $entityId, public ?string $feedback = null) {}

    public function handle(): void
    {
        try {
            $token = config('services.telegram.bot_token');
            $chat = config('services.telegram.admin_chat_id');
            if (! is_string($token) || ! preg_match('/\A[0-9]+:[a-zA-Z0-9_-]+\z/D', $token)
                || ! is_string($chat) || ! preg_match('/\A-?[0-9]+\z/D', $chat)) {
                throw new RuntimeException('Telegram configuration unavailable.');
            }
            $group = $this->event === 'group_moderation' ? Group::withTrashed()->find($this->entityId) : null;
            $user = User::withTrashed()->find($group->owner_id ?? $this->entityId);
            if (! $user || ($this->event === 'group_moderation' && ! $group)) {
                return;
            }
            $label = match ($this->event) {
                'feedback' => 'Сообщение психолога',
                'psychologist_pending' => 'Психолог ожидает одобрения',
                'group_moderation' => 'Группа ожидает модерации',
                default => throw new RuntimeException('Unknown Telegram event.'),
            };
            $text = $label."\nПсихолог #".$user->id."\n".mb_substr(PsychologistPages::profile($user)['name'], 0, 160)
                ."\n".mb_substr($user->email, 0, 254);
            if ($group) {
                $text .= "\nГруппа #".$group->id.': '.mb_substr($group->title, 0, 255);
            }
            $text .= "\n".route($group ? 'admin.groups.show' : 'admin.psychologists.show', $group ?? $user);
            if ($this->event === 'feedback') {
                $text .= "\n\n".$this->feedback;
            }
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chat, 'text' => $text, 'link_preview_options' => ['is_disabled' => true],
                ]);
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram delivery rejected.');
            }
        } catch (Throwable) {
            // Never retain the HTTP exception: its URL contains the bot token.
            Log::error('telegram.delivery_failed', ['event' => $this->event, 'entity_id' => $this->entityId, 'attempt' => $this->attempts()]);
            throw new RuntimeException('Telegram delivery failed; retry the queued job.');
        }
    }
}
