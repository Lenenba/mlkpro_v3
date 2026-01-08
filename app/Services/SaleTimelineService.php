<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Sale;
use App\Models\User;

class SaleTimelineService
{
    public function record(?User $actor, Sale $sale, string $action, array $properties = [], ?string $description = null): void
    {
        ActivityLog::record($actor, $sale, $action, $properties, $description);
    }

    public function buildTimeline(Sale $sale): array
    {
        $logs = ActivityLog::query()
            ->where('subject_type', $sale->getMorphClass())
            ->where('subject_id', $sale->id)
            ->orderBy('created_at')
            ->get(['id', 'action', 'description', 'properties', 'created_at']);

        return $logs->map(function (ActivityLog $log) {
            $properties = (array) ($log->properties ?? []);
            $label = $this->formatLabel($log->action, $properties, $log->description);

            return [
                'id' => $log->id,
                'action' => $log->action,
                'label' => $label,
                'created_at' => $log->created_at,
                'meta' => $properties,
            ];
        })->values()->all();
    }

    private function formatLabel(string $action, array $properties, ?string $description): string
    {
        if ($description) {
            return $description;
        }

        $statusLabels = [
            'draft' => 'Brouillon',
            'pending' => 'En attente',
            'paid' => 'Payee',
            'canceled' => 'Annulee',
        ];

        $fulfillmentLabels = [
            'pending' => 'Commande recue',
            'preparing' => 'Preparation',
            'out_for_delivery' => 'En cours de livraison',
            'ready_for_pickup' => 'Pret a retirer',
            'completed' => 'Livree',
            'confirmed' => 'Confirmee',
        ];

        return match ($action) {
            'sale_created' => 'Commande creee',
            'sale_updated' => 'Commande mise a jour',
            'sale_canceled' => 'Commande annulee',
            'sale_reordered' => 'Commande recommendee',
            'sale_pickup_confirmed' => 'Retrait confirme',
            'sale_delivery_confirmed' => 'Reception confirmee',
            'sale_eta_updated' => !empty($properties['scheduled_for'])
                ? 'Nouvelle estimation: ' . $properties['scheduled_for']
                : 'Nouvelle estimation enregistree',
            'sale_status_changed' => 'Statut commande: ' . ($statusLabels[$properties['status_to'] ?? ''] ?? ($properties['status_to'] ?? '')),
            'sale_fulfillment_changed' => 'Statut livraison: ' . ($fulfillmentLabels[$properties['fulfillment_to'] ?? ''] ?? ($properties['fulfillment_to'] ?? '')),
            default => 'Mise a jour commande',
        };
    }
}
