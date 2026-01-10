<?php

namespace App\Http\Controllers;

use App\Services\Assistant\AssistantInterpreter;
use App\Services\Assistant\AssistantQuoteService;
use App\Services\Assistant\AssistantWorkflowService;
use App\Services\Assistant\OpenAiRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AssistantController extends Controller
{
    public function message(
        Request $request,
        AssistantInterpreter $interpreter,
        AssistantQuoteService $quoteService,
        AssistantWorkflowService $workflowService
    ): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'context' => 'nullable|array',
        ]);

        $context = $validated['context'] ?? [];
        if (!empty($context['pending_action'])) {
            $pendingAction = $context['pending_action'];
            $message = $validated['message'];

            if ($this->isConfirmation($message)) {
                $type = $pendingAction['type'] ?? '';
                if ($type === 'create_quote') {
                    return response()->json(
                        $quoteService->execute($pendingAction['payload'] ?? [], $user)
                    );
                }

                return response()->json(
                    $workflowService->execute($pendingAction, $user)
                );
            }

            if ($this->isRejection($message)) {
                return response()->json([
                    'status' => 'cancelled',
                    'message' => 'Action annulee.',
                    'context' => [
                        'pending_action' => null,
                    ],
                ]);
            }

            $summary = $pendingAction['summary'] ?? 'Une action est en attente.';
            return response()->json([
                'status' => 'needs_confirmation',
                'message' => $summary . "\nConfirmer ? (oui/non)",
                'context' => [
                    'pending_action' => $pendingAction,
                ],
            ]);
        }

        $context['last_message'] = $validated['message'];

        if (!config('services.openai.key')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Assistant non configure. Contactez un administrateur.',
            ], 422);
        }

        if ($this->isStructureChangeRequest($validated['message'])) {
            return response()->json([
                'status' => 'not_allowed',
                'message' => 'Assistant cannot change the app structure. It can only create or read workflow data.',
            ], 422);
        }

        try {
            $interpretation = $interpreter->interpret($validated['message'], $validated['context'] ?? []);
        } catch (OpenAiRequestException $exception) {
            logger()->warning('Assistant OpenAI error.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($validated['message'], 160),
                'status' => $exception->status(),
                'type' => $exception->type(),
                'api_message' => $exception->apiMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => $exception->userMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            logger()->error('Assistant interpretation failed.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($validated['message'], 160),
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Assistant indisponible. Reessayez plus tard.',
            ], 500);
        } catch (Throwable $exception) {
            logger()->error('Assistant failed.', [
                'user_id' => $user->id,
                'message_preview' => Str::limit($validated['message'], 160),
                'error' => $exception->getMessage(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Assistant indisponible. Reessayez plus tard.',
            ], 500);
        }

        $contextIntent = $context['intent'] ?? null;
        if ($contextIntent && $interpretation['intent'] === 'unknown') {
            $interpretation['intent'] = $contextIntent;
        }

        if ($interpretation['intent'] === 'create_quote') {
            return response()->json(
                $quoteService->handle($interpretation, $user, $context)
            );
        }

        if (in_array($interpretation['intent'], [
            'create_work',
            'create_invoice',
            'send_quote',
            'accept_quote',
            'convert_quote',
            'mark_invoice_paid',
            'update_work_status',
            'create_customer',
            'create_property',
            'create_category',
            'create_product',
            'create_service',
            'create_team_member',
            'read_notifications',
            'list_quotes',
            'list_works',
            'list_invoices',
            'list_customers',
            'show_quote',
            'show_work',
            'show_invoice',
            'show_customer',
            'create_task',
            'update_task_status',
            'assign_task',
            'update_checklist_item',
            'create_request',
            'convert_request',
            'send_invoice',
            'remind_invoice',
            'schedule_work',
            'assign_work_team',
        ], true)) {
            return response()->json(
                $workflowService->handle($interpretation, $user, $context)
            );
        }

        return response()->json([
            'status' => 'unknown',
            'message' => 'Je peux creer et gerer des devis, factures et jobs, creer des clients/proprietes/categories/produits/services/membres, lire des listes et details, gerer des tasks/checklists, creer/convertir des requests, envoyer/relancer des factures et lire les notifications.',
        ]);
    }

    private function isStructureChangeRequest(string $message): bool
    {
        $normalized = Str::lower($message);

        return preg_match(
            '/\b(champ|colonne|schema|migration|interface|ui|layout|design|formulaire|composant|component|bouton|menu|sidebar|onglet|tab|fenetre|window)\b/u',
            $normalized
        ) === 1;
    }

    private function isConfirmation(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        return preg_match('/\b(oui|ok|okay|confirm|confirme|valide|daccord|yes|y|go)\b/u', $normalized) === 1;
    }

    private function isRejection(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        return preg_match('/\b(non|no|annule|annuler|cancel|stop)\b/u', $normalized) === 1;
    }
}
