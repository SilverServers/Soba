<?php

declare(strict_types=1);

namespace Cms\Controllers\Public;

use Cms\Commerce\CustomerAccount;
use Cms\Commerce\Customers;
use Cms\Helpers\Template;
use Cms\Security\Csrf;
use Cms\Security\Session;
use Cms\SEO\Head;

/**
 * /account — A CUSTOMER'S ORDERS AND ADDRESSES, behind a link to their inbox
 * (`Commerce\CustomerAccount`).
 *
 *   GET  /account                 their orders, or the form that asks for a link
 *   POST /account                 a link asked for: the same answer whoever asks
 *   GET  /account/enter/{token}   the link: a page with one button
 *   POST /account/enter/{token}   the button: the link spent, the customer in
 *   POST /account/leave           signed out
 *
 * Every POST carries the CSRF token and answers 303, as the basket's do.
 */
class AccountController
{
    public function show(array $vars = []): void
    {
        $email  = CustomerAccount::email();
        $orders = $email !== null ? Customers::orders($email) : [];
        $last   = $email !== null ? CustomerAccount::lastAddresses($email) : [];

        $this->render('shop/account.php', 'Your orders', compact('email', 'orders', 'last'));
    }

    public function requestLink(array $vars = []): void
    {
        $this->requireCsrf();
        CustomerAccount::sendLink((string) ($_POST['email'] ?? ''));
        // THE SAME SENTENCE WHATEVER WAS TYPED: the page never says who has
        // shopped here.
        $this->notice('If that address has ordered here, a link to sign in is on its way to it.');
        $this->redirect(BASE_URL . '/account');
    }

    public function enterPage(array $vars = []): void
    {
        $token = (string) ($vars['token'] ?? '');
        $good  = CustomerAccount::linkIsGood($token);

        $this->render('shop/account-enter.php', 'Sign in', compact('token', 'good'));
    }

    public function enter(array $vars = []): void
    {
        $this->requireCsrf();
        $token = (string) ($vars['token'] ?? '');
        if (!CustomerAccount::enter($token)) {
            $this->redirect(BASE_URL . '/account/enter/' . $token);
        }
        $this->redirect(BASE_URL . '/account');
    }

    public function leave(array $vars = []): void
    {
        $this->requireCsrf();
        CustomerAccount::leave();
        $this->notice('You are signed out.');
        $this->redirect(BASE_URL . '/account');
    }

    // ── Plumbing, as the basket's ───────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function render(string $template, string $title, array $data): void
    {
        Head::set([
            'title'  => $title . ' — ' . (string) (Head::globalTokens()['site_name'] ?? ''),
            'robots' => 'noindex, nofollow',
        ]);
        $pageTitle = $title;
        extract($data, EXTR_SKIP);

        ob_start();
        require Template::locate($template);
        $pageContent = ob_get_clean();
        require Template::locate('base.php');
    }

    private function notice(string $text): void
    {
        Session::ensure();
        $_SESSION['shop_notice'] = ['text' => $text, 'tone' => 'success', 'basket' => false];
    }

    private function requireCsrf(): void
    {
        if (!Csrf::validate()) {
            http_response_code(400);
            require Template::locate('404.php');
            exit;
        }
    }

    private function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }
}
