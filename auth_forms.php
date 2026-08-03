<div class="glass-card rounded-4 p-4 shadow-lift">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <div class="text-uppercase small text-secondary">Access Portal</div>
            <h4 class="fw-semibold mb-0">Sign in or create</h4>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-toggle <?php echo $activeTab === 'login' ? 'active' : ''; ?>" data-target="login">Login</button>
            <button class="btn btn-toggle <?php echo $activeTab === 'register' ? 'active' : ''; ?>" data-target="register">Sign up</button>
        </div>
    </div>
    <div id="login" class="tab-pane <?php echo $activeTab === 'login' ? '' : 'd-none'; ?>">
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="login">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control placeholder-muted" placeholder="name@example.com" value="<?php echo h((($_POST['action'] ?? '') === 'login') ? ($_POST['email'] ?? '') : ''); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control placeholder-muted" placeholder="Password" autocomplete="current-password" required>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="rememberMe" name="remember_me">
                <label class="form-check-label" for="rememberMe">
                    Keep me signed in on this device
                </label>
            </div>
            <div class="d-grid">
                <button class="btn btn-success btn-lg" style="background: var(--accent); border-color: var(--accent);">Login</button>
            </div>
        </form>
        <div class="mt-3 pt-3 border-top">
            <div class="text-muted small mb-2">Don’t want to use a password?</div>
            <form method="POST" class="row g-2 align-items-end" novalidate>
                <input type="hidden" name="action" value="magic_link">
                <div class="col-12 col-sm">
                    <label class="form-label">Email a sign-in link</label>
                    <input type="email" name="email" class="form-control placeholder-muted" placeholder="name@example.com" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
                <div class="col-12 col-sm-auto d-grid">
                    <button class="btn btn-outline-success btn-lg">Send link</button>
                </div>
            </form>
        </div>
    </div>
    <div id="register" class="tab-pane <?php echo $activeTab === 'register' ? '' : 'd-none'; ?>">
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="register">
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label">First name</label>
                    <input type="text" name="first_name" class="form-control placeholder-muted" placeholder="First name" value="<?php echo h((($_POST['action'] ?? '') === 'register') ? ($_POST['first_name'] ?? '') : ''); ?>" autocomplete="given-name">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Last name</label>
                    <input type="text" name="last_name" class="form-control placeholder-muted" placeholder="Last name" value="<?php echo h((($_POST['action'] ?? '') === 'register') ? ($_POST['last_name'] ?? '') : ''); ?>" autocomplete="family-name">
                </div>
                <div class="col-12">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control placeholder-muted" placeholder="name@example.com" value="<?php echo h((($_POST['action'] ?? '') === 'register') ? ($_POST['email'] ?? '') : ''); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control placeholder-muted" placeholder="Password" autocomplete="new-password" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Confirm password</label>
                    <input type="password" name="confirm_password" class="form-control placeholder-muted" placeholder="Confirm password" autocomplete="new-password" required>
                </div>
                <div class="col-12 d-grid">
                    <button class="btn btn-success btn-lg" style="background: var(--accent); border-color: var(--accent);">Create account</button>
                </div>
            </div>
        </form>
    </div>
</div>
