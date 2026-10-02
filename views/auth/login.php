<div class="auth-wrap">
  <div class="ascii-stream" data-ascii="stream" aria-hidden="true"></div>
  <p class="eyebrow">Sign in</p>
  <div class="brand-big">Freelance<span>Queue</span></div>
  <p class="tagline">One shared queue for you and your clients.</p>

  <div class="card">
    <h1>Welcome back</h1>
    <form method="post" action="<?= e(url('auth.login')) ?>">
      <?= csrf_input() ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>"
               autocomplete="email" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary block">Sign in</button>
    </form>
  </div>

  <p class="auth-alt">
    New here? <a href="<?= e(url('auth.signup')) ?>">Create an account</a>
  </p>
</div>
