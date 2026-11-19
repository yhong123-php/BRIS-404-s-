<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Assistant\AssistantService;
use App\Services\Assistant\OllamaGateway;
use Illuminate\Http\Request;

/**
 * H1 — AI-Powered Barangay Assistant endpoints (/api/assistant/*), behind the
 * same Sanctum, permission, throttle, and audit middleware as the rest of the
 * API (SDD §4.22).
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly OllamaGateway $gateway,
    ) {}

    public function status(Request $request)
    {
        $categories = $this->assistant->allowedCategories($request->user());
        $available = $this->gateway->isAvailable();

        return response()->json([
            'data' => [
                'available' => $available,
                'model' => $this->gateway->model(),
                'categories' => array_map(fn ($c) => ['key' => $c, 'label' => AssistantService::CATEGORY_LABELS[$c]], $categories),
            ],
        ]);
    }

    public function current(Request $request)
    {
        $conversation = $this->assistant->currentConversation($request->user());

        return response()->json(['data' => $this->presentConversation($conversation)]);
    }

    /** "New conversation" clears the previous context (SRS §3.2.3.1). */
    public function newConversation(Request $request)
    {
        $conversation = $this->assistant->startConversation($request->user());

        return response()->json(['data' => $this->presentConversation($conversation)], 201);
    }

    public function message(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $conversation = AiConversation::where('id', $data['conversation_id'])
            ->where('user_id', $request->user()->id)
            ->where('delete_after', '>', now())
            ->firstOrFail();

        // Long-running local model calls: lift PHP's default 30s limit for this request only.
        set_time_limit(max(120, (int) config('services.ollama.timeout') * 2 + 30));

        $reply = $this->assistant->ask($request->user(), $conversation, trim($data['message']));

        return response()->json(['data' => $this->presentMessage($reply)]);
    }

    private function presentConversation(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'started_at' => $conversation->started_at,
            'delete_after' => $conversation->delete_after,
            'messages' => $conversation->messages()->get()->map(fn (AiMessage $m) => $this->presentMessage($m))->all(),
        ];
    }

    private function presentMessage(AiMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender' => $message->sender,
            'content' => $message->content,
            'records' => $message->records,
            'created_at' => $message->created_at,
        ];
    }
}
