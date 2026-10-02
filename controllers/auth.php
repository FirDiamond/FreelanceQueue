<?php
// Signup, login, logout.

declare(strict_types=1);

/**
 * Only allow same-site relative redirects. Without this, ?next=https://evil
 * would turn our own login form into an open redirect.
 */
function ctrl_auth_safe_next(?string $target): ?string
{
    if (!$target) {
        return null;
    }
    if (str_starts_with($target, '//')) {
        return null;
    }
    if (!str_starts_with($target, '/')) {
        return null;
    }
    $parts = parse_url($target);
    if ($parts === false || !empty($parts['scheme']) || !empty($parts['host'])) {
        return null;
    }
    return $target;
}

function ctrl_auth_index(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirect(url(home_route_for($_SESSION['role'] ?? null)));
    }
    // Anonymous visitors get the poster landing page, not a bare login form.
    // It supplies its own header (chrome:false) and runs full-bleed. The queue
    // core is a live ASCII aperture (data-ascii="core"), so it needs ascii.js.
    add_script('/assets/js/ascii.js');
    render('landing', [
        'title'           => 'FreelanceQueue — let the backlog run itself',
        'container_class' => 'bleed',
        'chrome'          => false,
    ]);
}

function ctrl_auth_login(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirect(url(home_route_for($_SESSION['role'] ?? null)));
    }

    $email = '';
    if (request_method() === 'POST') {
        $email = (string)($_POST['email'] ?? '');
        try {
            $user = auth_login(db(), $email, (string)($_POST['password'] ?? ''), client_ip());
            login_user($user);
            flash('Welcome back, ' . $user['full_name'] . '.', 'success');
            $target = ctrl_auth_safe_next($_GET['next'] ?? null) ?? url(home_route_for($user['role']));
            redirect($target);
        } catch (AuthError $e) {
            flash($e->getMessage(), 'error');
        }
    }

    add_script('/assets/js/ascii.js');
    render('auth/login', ['title' => 'Sign in', 'email' => $email, 'container_class' => 'narrow']);
}

function ctrl_auth_signup(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirect(url(home_route_for($_SESSION['role'] ?? null)));
    }

    $form = ['email' => '', 'full_name' => '', 'role' => 'client', 'headline' => ''];
    if (request_method() === 'POST') {
        $form = [
            'email'     => (string)($_POST['email'] ?? ''),
            'full_name' => (string)($_POST['full_name'] ?? ''),
            'role'      => (string)($_POST['role'] ?? ''),
            'headline'  => (string)($_POST['headline'] ?? ''),
        ];
        try {
            $user = auth_signup(
                db(),
                $form['email'],
                $form['full_name'],
                $form['role'],
                (string)($_POST['password'] ?? ''),
                (string)($_POST['confirm'] ?? ''),
                $form['headline']
            );
            login_user($user);
            if ($user['role'] === 'freelancer') {
                flash('Account created. Add your services next - your queue stays closed until you open it.', 'success');
            } else {
                flash('Account created. Find a freelancer to join.', 'success');
            }
            redirect(url(home_route_for($user['role'])));
        } catch (AuthError $e) {
            flash($e->getMessage(), 'error');
        }
    }

    add_script('/assets/js/ascii.js');
    render('auth/signup', ['title' => 'Create account', 'form' => $form, 'container_class' => 'narrow']);
}

function ctrl_auth_logout(): void
{
    logout_user();
    flash('You have been signed out.', 'success');
    redirect(url('auth.login'));
}
