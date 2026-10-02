<div class="auth-wrap">
  <div class="ascii-stream" data-ascii="stream" aria-hidden="true"></div>
  <p class="eyebrow">Create account</p>
  <div class="brand-big">Freelance<span>Queue</span></div>
  <p class="tagline">Create an account to run a queue or join one.</p>

  <div class="card">
    <h1>Get started</h1>
    <form method="post" action="<?= e(url('auth.signup')) ?>">
      <?= csrf_input() ?>

      <div class="field">
        <label>I am a&hellip;</label>
        <div class="role-choice">
          <label>
            <input type="radio" name="role" value="client"
                   <?= $form['role'] !== 'freelancer' ? 'checked' : '' ?>>
            Client
            <span class="role-desc">Join a freelancer's queue and track my turn</span>
          </label>
          <label>
            <input type="radio" name="role" value="freelancer"
                   <?= $form['role'] === 'freelancer' ? 'checked' : '' ?>>
            Freelancer
            <span class="role-desc">Run my own queue and serve clients</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>"
               autocomplete="name" required>
      </div>

      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($form['email']) ?>"
               autocomplete="email" required>
        <div class="hint">Queue notifications are sent here.</div>
      </div>

      <div class="field" id="headline-field">
        <label for="headline">Headline <span class="muted">(optional)</span></label>
        <input type="text" id="headline" name="headline" value="<?= e($form['headline']) ?>"
               maxlength="120" placeholder="e.g. Brand &amp; logo design">
        <div class="hint">Shown to clients browsing for a freelancer.</div>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required minlength="8">
        <div class="hint">At least 8 characters.</div>
      </div>

      <div class="field">
        <label for="confirm">Confirm password</label>
        <input type="password" id="confirm" name="confirm"
               autocomplete="new-password" required minlength="8">
      </div>

      <button type="submit" class="btn btn-primary block">Create account</button>
    </form>
  </div>

  <p class="auth-alt">
    Already have an account? <a href="<?= e(url('auth.login')) ?>">Sign in</a>
  </p>
</div>

<script>
  /* The headline only means anything for a freelancer, so hide it otherwise. */
  (function () {
    var field = document.getElementById("headline-field");
    var radios = document.querySelectorAll('input[name="role"]');
    function sync() {
      var isFreelancer = document.querySelector('input[name="role"]:checked').value === "freelancer";
      field.style.display = isFreelancer ? "" : "none";
    }
    radios.forEach(function (r) { r.addEventListener("change", sync); });
    sync();
  })();
</script>
