<?php

namespace App\Http\Requests\Tasks;

class UpdateTaskRequest extends TaskWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->taskStateRules(true);

        if ($this->isManager()) {
            $rules = array_merge($rules, $this->managerRules());
        }

        return $rules;
    }

    public function isManager(): bool
    {
        $user = $this->user();
        $accountId = $this->accountId();

        if (! $user) {
            return false;
        }

        if ($user->id === $accountId) {
            return true;
        }

        $membership = $user->teamMembership()->first();

        return (bool) ($membership && $membership->role === 'admin');
    }
}
