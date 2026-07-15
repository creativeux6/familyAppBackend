<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Groups\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Private-channel auth for mobile/API clients using Sanctum bearer tokens.
 * The default /broadcasting/auth web route is session-oriented and returns 403
 * for bearer-only app requests, which breaks Reverb subscriptions.
 *
 * A 403 here (with an authenticated user) almost always means channel authorization
 * failed — e.g. not a member of private-group.{uuid}, or private-user.{uuid}
 * does not match the token owner — not a missing Sanctum token (that is 401).
 */
class BroadcastingAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        $channelName = (string) $request->input('channel_name', '');
        $socketId = (string) $request->input('socket_id', '');

        if ($channelName === '' || $socketId === '') {
            return response()->json([
                'message' => 'Forbidden',
                'error' => 'missing_params',
                'detail' => 'socket_id and channel_name are required.',
                'channel_name' => $channelName !== '' ? $channelName : null,
            ], 403);
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        $denial = $this->explainDenial($user, $channelName);
        if ($denial !== null) {
            return response()->json([
                'message' => 'Forbidden',
                'error' => 'channel_forbidden',
                'detail' => $denial,
                'channel_name' => $channelName,
                'user_uuid' => $user->uuid,
            ], 403);
        }

        try {
            return Broadcast::auth($request);
        } catch (AccessDeniedHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage() ?: 'Forbidden',
                'error' => 'channel_forbidden',
                'detail' => $this->explainDenial($user, $channelName)
                    ?? 'Broadcast channel authorization returned false.',
                'channel_name' => $channelName,
                'user_uuid' => $user->uuid,
            ], 403);
        }
    }

    private function explainDenial(User $user, string $channelName): ?string
    {
        if (str_starts_with($channelName, 'private-user.')) {
            $targetUuid = substr($channelName, strlen('private-user.'));
            if ($targetUuid === '' || $user->uuid !== $targetUuid) {
                return 'private-user channel only allows the token owner. '
                    ."Requested user={$targetUuid}, token user={$user->uuid}.";
            }

            return null;
        }

        if (str_starts_with($channelName, 'private-group.')) {
            $groupUuid = substr($channelName, strlen('private-group.'));
            if ($groupUuid === '') {
                return 'private-group channel is missing a group UUID.';
            }

            $isMember = app(GroupService::class)->isGroupMember($user, $groupUuid);
            if (! $isMember) {
                return 'Not a member of this group, so private-group auth is denied. '
                   .'Join/accept the group (or use an account that is a member) before subscribing.';
            }

            return null;
        }

        return 'Unknown or unsupported channel. Expected private-user.{uuid} or private-group.{uuid}.';
    }
}
