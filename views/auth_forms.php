<div class="glass-card rounded-4 p-4 shadow-lift">
    <?php if (($authView ?? 'default') === 'forgot'): ?>
        <div class="mb-3">
            <div class="text-uppercase small text-secondary">Password Help</div>
            <h4 class="fw-semibold mb-1">Forgot your password?</h4>
            <div class="text-muted small">We’ll email you a one-time reset link.</div>
        </div>
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="password_reset_request">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control placeholder-muted" placeholder="name@example.com" value="<?php echo h((($_POST['action'] ?? '') === 'password_reset_request') ? ($_POST['email'] ?? '') : ''); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-success btn-lg">Email reset link</button>
                <a class="btn btn-outline-success btn-lg" href="<?php echo h($basePath); ?>/account">Back to login</a>
            </div>
        </form>
    <?php elseif (($authView ?? 'default') === 'magic'): ?>
        <div class="mb-3">
            <div class="text-uppercase small text-secondary">Passwordless Sign In</div>
            <h4 class="fw-semibold mb-1">Email me a sign-in link</h4>
            <div class="text-muted small">We’ll send a one-time link that signs you in without a password.</div>
        </div>
        <form method="POST" novalidate>
            <input type="hidden" name="action" value="magic_link">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control placeholder-muted" placeholder="name@example.com" value="<?php echo h((($_POST['action'] ?? '') === 'magic_link') ? ($_POST['email'] ?? '') : ''); ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </div>
            <div class="d-grid gap-2">
                <button class="btn btn-success btn-lg">Send sign-in link</button>
                <a class="btn btn-outline-success btn-lg" href="<?php echo h($basePath); ?>/account">Back to login</a>
            </div>
        </form>
    <?php elseif (($authView ?? 'default') === 'reset'): ?>
        <?php if (!empty($resetToken) && $resetTokenInfo): ?>
            <div class="mb-3">
                <div class="text-uppercase small text-secondary">Password Reset</div>
                <h4 class="fw-semibold mb-1">Set a new password</h4>
                <div class="text-muted small">Resetting password for <?php echo h($resetTokenInfo['email'] ?? 'your account'); ?>.</div>
            </div>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="password_reset">
                <input type="hidden" name="token" value="<?php echo h($resetToken); ?>">
                <div class="col-12">
                    <label class="form-label">New password</label>
                    <input type="password" class="form-control" name="password" placeholder="New password" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Confirm password</label>
                    <input type="password" class="form-control" name="confirm_password" placeholder="Confirm password" required>
                </div>
                <div class="col-12 d-grid gap-2">
                    <button class="btn btn-success btn-lg">Set new password</button>
                    <a class="btn btn-outline-success btn-lg" href="<?php echo h($basePath); ?>/account?auth=forgot">Back to reset help</a>
                </div>
            </form>
        <?php else: ?>
            <div class="mb-3">
                <div class="text-uppercase small text-secondary">Password Reset</div>
                <h4 class="fw-semibold mb-1">Reset link expired</h4>
                <div class="text-muted small">This reset link is invalid or has already been used.</div>
            </div>
            <div class="d-grid">
                <a class="btn btn-outline-success btn-lg" href="<?php echo h($basePath); ?>/account?auth=forgot">Request a new reset link</a>
            </div>
        <?php endif; ?>
    <?php else: ?>
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
                    <button class="btn btn-success btn-lg">Login</button>
                </div>
            </form>
            <div class="mt-3 pt-3 border-top auth-link-row">
                <a class="btn btn-outline-success btn-sm auth-link-btn" href="<?php echo h($basePath); ?>/account?auth=forgot">Forgot password</a>
                <a class="btn btn-outline-success btn-sm auth-link-btn" href="<?php echo h($basePath); ?>/account?auth=magic">Email sign-in link</a>
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
                        <button class="btn btn-success btn-lg">Create account</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
