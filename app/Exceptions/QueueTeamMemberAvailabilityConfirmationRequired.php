<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QueueTeamMemberAvailabilityConfirmationRequired extends \RuntimeException
{
    public const CODE = 'queue_team_member_availability_confirmation_required';

    public function __construct(
        public readonly int $teamMemberId,
        public readonly string $teamMemberName,
        public readonly string $action
    ) {
        parent::__construct('The selected team member is marked as busy, but has no active queue service.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'availability_confirmation' => [
                'team_member_id' => $this->teamMemberId,
                'team_member_name' => $this->teamMemberName,
                'action' => $this->action,
            ],
        ], Response::HTTP_CONFLICT);
    }
}
