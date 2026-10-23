{{-- Form-level feedback for a sign-in form: a failed attempt, a signed-out notice, or an expired session. --}}
@if ($errors->has('form'))
    <x-ui.alert variant="danger" {{ $attributes }}>{{ $errors->first('form') }}</x-ui.alert>
@elseif (session('status'))
    <x-ui.alert variant="success" {{ $attributes }}>{{ session('status') }}</x-ui.alert>
@elseif (request()->boolean('expired'))
    <x-ui.alert variant="warning" title="Your session expired" {{ $attributes }}>For your security you were signed out — your session ended or your account was updated. Sign in again to continue; anything you hadn't saved was not submitted.</x-ui.alert>
@endif
