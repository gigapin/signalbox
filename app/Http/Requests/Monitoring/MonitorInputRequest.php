<?php

declare(strict_types=1);

/** Adapt authorized HTTP input to the reusable validator without merging old secrets into the request. */

namespace App\Http\Requests\Monitoring;

use App\Data\Monitoring\MonitorInput;
use App\Data\Monitoring\ValidatedMonitorInput;
use App\Exceptions\MissingTenantContext;
use App\Models\User;
use App\Services\Monitoring\MonitorInputValidator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Fail closed until future CRUD policies grant create/update; no endpoint is registered by this adapter. */
abstract class MonitorInputRequest extends FormRequest
{
    /** @var ValidatedMonitorInput|null Private validation result, absent until successful validation. */
    private ?ValidatedMonitorInput $result = null;

    /**
     * @param Factory $factory Framework validator factory.
     * @param MonitorInputValidator $input Reusable domain-input validation boundary.
     * @return Validator Framework error adapter; existing values never enter original HTTP input.
     */
    public function validator(Factory $factory, MonitorInputValidator $input): Validator
    {
        $validator = $factory->make([], []);
        $validator->after(function (Validator $validator) use ($input): void {
            try {
                $this->result = $input->validate($this->all(), $this->currentInput());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });

        return $validator;
    }

    /**
     * @return ValidatedMonitorInput Complete private snapshot and explicit normalized patch.
     * @throws LogicException When consumed before successful validation.
     */
    public function monitorInput(): ValidatedMonitorInput
    {
        return $this->result ?? throw new LogicException('Monitor input must be validated before consumption.');
    }

    /**
     * @param mixed $key Optional dot-notation patch key, matching Laravel's validated helper contract.
     * @param mixed $default Fallback for an absent key.
     * @return mixed Only explicitly submitted normalized fields; use monitorInput for the complete candidate.
     */
    public function validated($key = null, $default = null): mixed
    {
        return data_get($this->monitorInput()->patch->values, $key, $default);
    }

    /** @return MonitorInput|null Authorized snapshot for updates, absent on creation. */
    protected function currentInput(): ?MonitorInput
    {
        return null;
    }

    /** @return int|null Active team only for a verified application user; absent context grants no access. */
    protected function authorizedTeamId(): ?int
    {
        $actor = $this->user();

        if (!$actor instanceof User || !$actor->hasVerifiedEmail()) {
            return null;
        }

        try {
            return app(TenantContext::class)->requireTeamId();
        } catch (MissingTenantContext) {
            return null;
        }
    }

    /**
     * @param Validator $validator Failed validator containing only safe field-level messages.
     * @throws ValidationException JSON errors retain Laravel's 422 contract; web errors flash no original input.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        $response = $this->redirector->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput([]);

        throw new ValidationException($validator, $response);
    }
}
