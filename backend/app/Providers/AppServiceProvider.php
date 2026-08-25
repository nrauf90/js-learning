<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordResetLinks();
    }

    /**
     * Point the reset email at the frontend, not at Laravel.
     *
     * The default link is a route on the API host, which here serves JSON and
     * has no reset form on it — the shopkeeper would land on a 404. The API and
     * the pages are separate origins in this app, so the link has to name the
     * frontend explicitly.
     *
     * The token rides in the URL *fragment*, not the query string. An email has
     * nowhere else to put it, but a fragment is never sent to a server: it stays
     * out of the frontend host's access logs, out of Referer headers, and out of
     * any proxy in between. It also survives static hosts that rewrite
     * /page.html to /page — that redirect drops the query string, which would
     * strip the token off every link this app ever mailed.
     *
     * It is still single-use, still expires in an hour (config/auth.php), and
     * still useless without the address it was issued for. reset-password.html
     * clears the fragment as soon as it has read it, so it does not linger in
     * browser history either.
     */
    private function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontend = rtrim((string) config('services.frontend.url', 'http://localhost:3000'), '/');

            return $frontend.'/reset-password.html#token='.$token
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });
    }
}
