<?php

namespace App\Http\Requests\Tasks;

class StoreTaskRequest extends TaskWriteRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            $this->managerRules(),
            $this->taskStateRules(false),
        );
    }
}
