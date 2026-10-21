<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/** Ответ на запрос сброса пароля не выдаёт, зарегистрирован ли e-mail: он такой же, как при успешной отправке. */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $message = __('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
