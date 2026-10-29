<?php

declare(strict_types=1);

/** Translate browser notification channel changes into lock-aware domain actions. */

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\ManageNotificationChannels;
use App\Exceptions\ChannelConfigurationInvalid;
use App\Exceptions\NotificationChannelRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ChannelActivationRequest;
use App\Http\Requests\Notifications\ConfirmChannelVerificationRequest;
use App\Http\Requests\Notifications\StoreNotificationChannelRequest;
use App\Http\Requests\Notifications\UpdateNotificationChannelRequest;
use App\Models\NotificationChannel;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Configuration errors arrive per field as `config.<field>`; state refusals on the `channel` key. The webhook
 * signing secret is returned only in a non-cacheable JSON response, never in Inertia props or session flash.
 */
final class NotificationChannelController extends Controller
{
    /** @param ManageNotificationChannels $channels Transactional channel changes. */
    public function __construct(private readonly ManageNotificationChannels $channels)
    {
    }

    /**
     * @param StoreNotificationChannelRequest $request Authorized channel shape.
     * @param int $team Route team.
     * @return RedirectResponse Previous page with `channel-created` status.
     */
    public function store(StoreNotificationChannelRequest $request, int $team): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): NotificationChannel => $this->channels->create($actor, $team, $request->type(), $request->string('name')->toString(), $request->configuration()));

        return back()->with('status', 'channel-created');
    }

    /**
     * @param UpdateNotificationChannelRequest $request Authorized replacement.
     * @param int $team Route team.
     * @param NotificationChannel $channel Channel bound inside the route team.
     * @return RedirectResponse Previous page with `channel-updated` status.
     */
    public function update(UpdateNotificationChannelRequest $request, int $team, NotificationChannel $channel): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): NotificationChannel => $this->channels->update($actor, $team, $channel->id, $request->string('name')->toString(), $request->configuration()));

        return back()->with('status', 'channel-updated');
    }

    /**
     * @param Request $request Verified browser identity; the action authorizes under lock.
     * @param int $team Route team.
     * @param NotificationChannel $channel Channel bound inside the route team.
     * @return RedirectResponse Previous page with `channel-deleted` status.
     */
    public function destroy(Request $request, int $team, NotificationChannel $channel): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn () => $this->channels->delete($actor, $team, $channel->id));

        return back()->with('status', 'channel-deleted');
    }

    /**
     * @param Request $request Verified browser identity; the action authorizes under lock.
     * @param int $team Route team.
     * @param NotificationChannel $channel Channel bound inside the route team.
     * @return RedirectResponse Previous page with `channel-code-sent` status.
     */
    public function sendVerification(Request $request, int $team, NotificationChannel $channel): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn () => $this->channels->sendVerification($actor, $team, $channel->id));

        return back()->with('status', 'channel-code-sent');
    }

    /**
     * @param ConfirmChannelVerificationRequest $request Authorized six-digit code.
     * @param int $team Route team.
     * @param NotificationChannel $channel Channel bound inside the route team.
     * @return RedirectResponse Previous page with `channel-verified` status.
     */
    public function confirmVerification(ConfirmChannelVerificationRequest $request, int $team, NotificationChannel $channel): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $this->rejectable(fn (): NotificationChannel => $this->channels->confirmVerification($actor, $team, $channel->id, $request->code()));

        return back()->with('status', 'channel-verified');
    }

    /**
     * @param ChannelActivationRequest $request Authorized requested state.
     * @param int $team Route team.
     * @param NotificationChannel $channel Channel bound inside the route team.
     * @return RedirectResponse Previous page with `channel-activated` or `channel-deactivated` status.
     */
    public function activation(ChannelActivationRequest $request, int $team, NotificationChannel $channel): RedirectResponse
    {
        $actor = $this->authenticatedUser($request);
        $active = $request->active();
        $this->rejectable(fn (): NotificationChannel => $this->channels->setActive($actor, $team, $channel->id, $active));

        return back()->with('status', $active ? 'channel-activated' : 'channel-deactivated');
    }

    /**
     * @param Request $request Verified browser identity; the action authorizes under lock.
     * @param int $team Route team.
     * @param NotificationChannel $channel Webhook channel bound inside the route team.
     * @return JsonResponse One-time plaintext signing secret, or 422 with the refusal for other types.
     */
    public function rotateSigningSecret(Request $request, int $team, NotificationChannel $channel): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $secret = $this->rejectable(fn (): string => $this->channels->rotateSigningSecret($actor, $team, $channel->id));

        return response()->json(['secret' => $secret]);
    }

    /**
     * @template TResult
     * @param Closure(): TResult $change Domain change that may refuse the request.
     * @return TResult Change result.
     * @throws ValidationException When the configuration is invalid or the channel state forbids the change.
     */
    private function rejectable(Closure $change): mixed
    {
        try {
            return $change();
        } catch (ChannelConfigurationInvalid $exception) {
            $messages = [];

            foreach ($exception->errors as $field => $message) {
                $messages['config.'.$field] = $message;
            }

            throw ValidationException::withMessages($messages);
        } catch (NotificationChannelRejected $exception) {
            throw ValidationException::withMessages(['channel' => $exception->getMessage()]);
        }
    }
}
