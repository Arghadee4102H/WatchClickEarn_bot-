// script.js
document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.expand();

    const API_URL = 'api.php';
    let userData = null;
    let currentEnergyInterval = null;
    let adCooldownInterval = null;
    let monetagZoneId = '9321934'; // Your Monetag Zone ID

    const loader = document.getElementById('loader');
    const appContainer = document.getElementById('app-container');
    const pages = document.querySelectorAll('.page');
    const navButtons = document.querySelectorAll('.nav-btn');

    const profileUsername = document.getElementById('profile-username');
    const profileTgId = document.getElementById('profile-tg-id');
    const profileUniqueId = document.getElementById('profile-unique-id');
    const profileJoinDate = document.getElementById('profile-join-date');
    const profilePoints = document.getElementById('profile-points');
    const profileReferralLink = document.getElementById('profile-referral-link');
    const copyReferralBtn = document.getElementById('copy-referral-btn');
    const profileTotalReferrals = document.getElementById('profile-total-referrals');
    const themeSelect = document.getElementById('theme-select');

    const tapCat = document.getElementById('tap-cat');
    const catImage = document.getElementById('cat-image');
    const tapPointsDisplay = document.getElementById('tap-points');
    const energyValueDisplay = document.getElementById('energy-value');
    const maxEnergyValueDisplay = document.getElementById('max-energy-value');
    const energyFill = document.getElementById('energy-fill');
    const dailyTapsLeftDisplay = document.getElementById('daily-taps-left');
    const tapMessage = document.getElementById('tap-message');

    const taskListDiv = document.getElementById('task-list');
    const taskMessage = document.getElementById('task-message');

    const adsPointsReward = document.getElementById('ads-points-reward');
    const adsWatchedToday = document.getElementById('ads-watched-today');
    const adsMaxDaily = document.getElementById('ads-max-daily');
    const watchAdBtn = document.getElementById('watch-ad-btn');
    const adStatusMessage = document.getElementById('ad-status-message');
    const adCooldownTimerDisplay = document.getElementById('ad-cooldown-timer');

    const withdrawCurrentPoints = document.getElementById('withdraw-current-points');
    const withdrawButtons = document.querySelectorAll('.withdraw-btn');
    const withdrawForm = document.getElementById('withdraw-form');
    const withdrawAmountDisplay = document.getElementById('withdraw-amount-display');
    const withdrawPointsAmountInput = document.getElementById('withdraw-points-amount');
    const withdrawMethodSelect = document.getElementById('withdraw-method');
    const withdrawDetailsLabel = document.getElementById('withdraw-details-label');
    const withdrawDetailsInput = document.getElementById('withdraw-details');
    const submitWithdrawalBtn = document.getElementById('submit-withdrawal-btn');
    const withdrawMessage = document.getElementById('withdraw-message');

    async function initializeApp() {
        showLoader(true);
        try {
            if (!tg.initDataUnsafe || !tg.initDataUnsafe.user) {
                handleError("Telegram user data not available. Please open via Telegram.", true);
                return;
            }

            const tgUser = tg.initDataUnsafe.user;
            const startParam = tg.initDataUnsafe.start_param || null;

            const response = await apiCall('init_user', {
                telegram_user_id: tgUser.id,
                username: tgUser.username || null,
                first_name: tgUser.first_name || tgUser.username || `User${tgUser.id}`, // Fallback for first_name
                referred_by_app_id: startParam
            });

            if (response.success && response.data) {
                userData = response.data;
                localStorage.setItem('userData', JSON.stringify(userData));
                updateAllUI();
                startEnergyRefill();
                loadTasks();
                checkAdCooldown();
                loadTheme(); // Load theme after user data is available for potential theme colors
                navigateToPage(localStorage.getItem('currentPage') || 'profile-page');
                appContainer.style.display = 'flex';
            } else {
                handleError(response.message || "Failed to initialize user.", true);
            }
        } catch (error) {
            console.error("Initialization error:", error);
            handleError("An error occurred during initialization. Check console.", true);
        } finally {
            showLoader(false);
        }
    }

    async function apiCall(action, data = {}) {
        try {
            const params = new URLSearchParams();
            params.append('action', action);
            if (userData && userData.telegram_user_id && action !== 'init_user') { // Only add if not init and available
                params.append('telegram_user_id', userData.telegram_user_id);
            }
             // For init_user, telegram_user_id is part of the data payload
            if (action === 'init_user' && data.telegram_user_id) {
                // No need to append again, it's in 'data'
            }

            for (const key in data) {
                params.append(key, data[key]);
            }

            const response = await fetch(API_URL, {
                method: 'POST',
                body: params
            });

            const responseText = await response.text(); // Get raw text first
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}. Response: ${responseText}`);
            }

            try {
                return JSON.parse(responseText); // Try to parse as JSON
            } catch (e) {
                // This is where the "Unexpected token '<'" error happens client-side
                console.error("Failed to parse JSON response:", responseText);
                throw new Error(`Invalid JSON response from server. Check server logs or API output. Content: ${responseText.substring(0,200)}...`);
            }

        } catch (error) {
            console.error(`API call failed for action ${action}:`, error);
            // Display a user-friendly message for network errors
            if (error.message.startsWith("Invalid JSON response")) {
                 handleError(error.message, false); // Show the raw content hint
            } else if (error.message.startsWith("HTTP error!")) {
                 handleError(`Server communication error. Please try again. (${error.message.split('.')[0]})`, false);
            }
            else {
                 handleError("Network error or server unreachable. Please check your connection.", false);
            }
            return { success: false, message: error.message || "API call failed." };
        }
    }

    function updateAllUI() {
        if (!userData) return;
        updateProfileUI();
        updateTapUI();
        updateAdsUI();
        updateWithdrawUI();
        // Update global point displays if they exist outside sections
        document.querySelectorAll('.global-points-display').forEach(el => el.textContent = formatPoints(userData.points));
    }

    function updateProfileUI() {
        profileUsername.textContent = userData.first_name || userData.username || 'User';
        profileTgId.textContent = userData.telegram_user_id;
        profileUniqueId.textContent = userData.unique_app_id;
        profileJoinDate.textContent = userData.created_at ? new Date(userData.created_at).toLocaleDateString() : 'N/A';
        profilePoints.textContent = formatPoints(userData.points);
        // ---- IMPORTANT: Replace 'YourBotUsername' with your actual bot username ----
        profileReferralLink.value = `https://t.me/WatchClickEarn_bot?start=${userData.unique_app_id}`;
        // -----------------------------------------------------------------------
        profileTotalReferrals.textContent = userData.total_referrals_verified;
    }

    function updateTapUI() {
        tapPointsDisplay.textContent = formatPoints(userData.points);
        energyValueDisplay.textContent = Math.floor(userData.energy);
        maxEnergyValueDisplay.textContent = userData.max_energy;
        const energyPercentage = (userData.energy / userData.max_energy) * 100;
        energyFill.style.width = `${Math.max(0, Math.min(100, energyPercentage))}%`;
        dailyTapsLeftDisplay.textContent = Math.max(0, userData.max_daily_taps - userData.daily_taps);
    }

    function updateAdsUI() {
        adsPointsReward.textContent = userData.points_per_ad || 50;
        adsWatchedToday.textContent = userData.daily_ads_watched_count;
        adsMaxDaily.textContent = userData.max_daily_ads;
        watchAdBtn.disabled = userData.daily_ads_watched_count >= userData.max_daily_ads;
    }

    function updateWithdrawUI() {
        withdrawCurrentPoints.textContent = formatPoints(userData.points);
    }

    function formatPoints(points) {
        return Number(points).toLocaleString();
    }

    function navigateToPage(pageId) {
        pages.forEach(page => page.classList.remove('active'));
        navButtons.forEach(btn => btn.classList.remove('active'));
        const targetPage = document.getElementById(pageId);
        const targetButton = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
        if (targetPage) targetPage.classList.add('active');
        if (targetButton) targetButton.classList.add('active');
        localStorage.setItem('currentPage', pageId);
        window.scrollTo(0, 0); // Scroll to top of new page
    }

    navButtons.forEach(button => {
        button.addEventListener('click', () => navigateToPage(button.dataset.page));
    });

    function applyTheme(themeName) {
        document.body.className = `theme-${themeName}`;
        localStorage.setItem('theme', themeName);
        themeSelect.value = themeName;
        const colors = {
            light: { header: '#f8f9fa', background: '#ffffff' },
            dark: { header: '#1e1e1e', background: '#121212' },
            blue: { header: '#b3e5fc', background: '#e0f2f7' }
        };
        tg.setHeaderColor(colors[themeName].header);
        tg.setBackgroundColor(colors[themeName].background);
    }

    function loadTheme() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        applyTheme(savedTheme);
    }

    themeSelect.addEventListener('change', (e) => applyTheme(e.target.value));

    copyReferralBtn.addEventListener('click', () => {
        profileReferralLink.select();
        profileReferralLink.setSelectionRange(0, 99999); // For mobile devices
        try {
            document.execCommand('copy');
            showTemporaryMessage(tapMessage, 'Referral link copied!', 'success', 2000); // Use tapMessage or a dedicated profile message element
            tg.HapticFeedback.notificationOccurred('success');
        } catch (err) {
            alert('Failed to copy. Please copy manually.');
        }
    });

    let lastTapTime = 0;
    const TAP_DEBOUNCE_MS = 50; // Reduced debounce

    tapCat.addEventListener('click', async () => {
        const now = Date.now();
        if (now - lastTapTime < TAP_DEBOUNCE_MS) return;
        lastTapTime = now;

        if (userData.energy >= userData.energy_per_tap && (userData.max_daily_taps - userData.daily_taps) > 0) {
            catImage.style.transform = 'scale(0.92)';
            setTimeout(() => catImage.style.transform = 'scale(1)', 80);
            tg.HapticFeedback.impactOccurred('light');

            const response = await apiCall('tap');
            if (response.success && response.data) {
                userData = response.data;
                updateAllUI();
                showTemporaryMessage(tapMessage, response.message || `+${response.points_earned || 0} Points!`, 'success');
            } else {
                 showTemporaryMessage(tapMessage, response.message || "Tap failed.", 'error');
                if (response.data) { userData = response.data; updateAllUI(); } // Sync if server sent updated data anyway
            }
        } else if (userData.energy < userData.energy_per_tap) {
            showTemporaryMessage(tapMessage, "Not enough energy!", 'error');
        } else {
            showTemporaryMessage(tapMessage, "Daily tap limit reached!", 'error');
        }
    });

    function startEnergyRefill() {
        if (currentEnergyInterval) clearInterval(currentEnergyInterval);
        currentEnergyInterval = setInterval(async () => {
            if (userData && userData.energy < userData.max_energy) {
                const response = await apiCall('sync_user_data'); // More reliable to sync with server
                if (response.success && response.data) {
                    userData = response.data;
                    updateTapUI(); // Only update tap UI to avoid full refresh if not needed
                }
            }
        }, 10000); // Sync energy every 10 seconds
    }

    async function loadTasks() {
        const response = await apiCall('get_tasks');
        taskListDiv.innerHTML = '';
        if (response.success && response.tasks) {
            if (response.tasks.length === 0) {
                 taskListDiv.innerHTML = '<p style="text-align:center; margin-top:20px;">No tasks available. Check back later!</p>';
                 return;
            }
            response.tasks.forEach(task => {
                const taskItem = document.createElement('div');
                taskItem.className = `task-item ${task.completed_today ? 'completed' : ''}`;
                taskItem.innerHTML = `
                    <div class="task-info">
                        <h4>${task.title}</h4>
                        <p>Reward: ${task.points} points</p>
                    </div>
                    <div class="task-action">
                        <button data-task-id="${task.id}" data-task-link="${task.link}" ${task.completed_today ? 'disabled' : ''}>
                            ${task.completed_today ? 'Completed' : 'Go to Task'}
                        </button>
                    </div>
                `;
                taskListDiv.appendChild(taskItem);
            });

            taskListDiv.querySelectorAll('.task-action button:not(:disabled)').forEach(button => {
                button.addEventListener('click', async (e) => {
                    const taskId = e.target.dataset.taskId;
                    const taskLink = e.target.dataset.taskLink;
                    tg.openLink(taskLink);
                    button.disabled = true;
                    button.textContent = 'Verifying...';

                    await new Promise(resolve => setTimeout(resolve, 4000)); // Verification delay

                    const completeResponse = await apiCall('complete_task', { task_id: taskId });
                    if (completeResponse.success) {
                        showTemporaryMessage(taskMessage, completeResponse.message || `Task completed! +${completeResponse.points_earned} points.`, 'success');
                        userData = completeResponse.data;
                        updateAllUI();
                        loadTasks(); // Refresh task list
                    } else {
                        showTemporaryMessage(taskMessage, completeResponse.message || "Task completion failed.", 'error');
                        button.disabled = false; // Re-enable if failed
                        button.textContent = 'Go to Task';
                    }
                });
            });
        } else {
            taskListDiv.innerHTML = `<p style="text-align:center; color:red;">${response.message || 'Could not load tasks.'}</p>`;
        }
    }

    function checkAdCooldown() {
        if (adCooldownInterval) clearInterval(adCooldownInterval);
        if (!userData || !userData.last_ad_watched_ts || userData.daily_ads_watched_count >= userData.max_daily_ads) {
            adCooldownTimerDisplay.textContent = userData && userData.daily_ads_watched_count >= userData.max_daily_ads ? "Daily limit reached" : "Ready";
            watchAdBtn.disabled = userData && userData.daily_ads_watched_count >= userData.max_daily_ads;
            return;
        }

        const lastAdTime = new Date(userData.last_ad_watched_ts).getTime();
        const adCooldownSeconds = userData.ad_cooldown_seconds || (3 * 60);

        const updateTimer = () => {
            const now = Date.now();
            const timePassed = (now - lastAdTime) / 1000;
            let timeLeft = Math.ceil(adCooldownSeconds - timePassed);

            if (timeLeft <= 0 || userData.daily_ads_watched_count >= userData.max_daily_ads) {
                clearInterval(adCooldownInterval);
                adCooldownTimerDisplay.textContent = userData.daily_ads_watched_count >= userData.max_daily_ads ? "Daily limit reached" : "Ready";
                watchAdBtn.disabled = userData.daily_ads_watched_count >= userData.max_daily_ads;
            } else {
                adCooldownTimerDisplay.textContent = `${Math.floor(timeLeft / 60)}m ${timeLeft % 60}s`;
                watchAdBtn.disabled = true;
            }
        };
        updateTimer(); // Initial call
        adCooldownInterval = setInterval(updateTimer, 1000);
    }


    watchAdBtn.addEventListener('click', () => {
        if (userData.daily_ads_watched_count >= userData.max_daily_ads) {
            showTemporaryMessage(adStatusMessage, "Daily ad limit reached.", 'error');
            return;
        }
        
        watchAdBtn.disabled = true;
        showTemporaryMessage(adStatusMessage, "Loading ad...", 'neutral', 0); // Persistent until ad resolves

        if (typeof show_9321934 === 'function') { // Ensure Monetag SDK function exists
            show_9321934() // Using Rewarded Interstitial
                .then(async () => {
                    tg.HapticFeedback.notificationOccurred('success');
                    const response = await apiCall('watched_ad');
                    if (response.success && response.data) {
                        userData = response.data;
                        updateAllUI(); // This will call updateAdsUI and checkAdCooldown
                        showTemporaryMessage(adStatusMessage, response.message || `+${response.points_earned} points!`, 'success');
                    } else {
                        showTemporaryMessage(adStatusMessage, response.message || "Failed to record ad view.", 'error');
                    }
                })
                .catch(e => {
                    console.error("Monetag Ad Error:", e);
                    showTemporaryMessage(adStatusMessage, "Ad failed or closed early.", 'error');
                    // Re-enable button or let cooldown logic handle it. For now, force cooldown check.
                    if(userData) checkAdCooldown(); else watchAdBtn.disabled = false;
                });
        } else {
            showTemporaryMessage(adStatusMessage, "Ad system not available.", 'error');
            console.error("Monetag function show_9321934 not found.");
            watchAdBtn.disabled = false; // Re-enable if SDK is missing
        }
    });


    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            const pointsToWithdraw = parseInt(button.dataset.points);
            if (userData.points < pointsToWithdraw) {
                showTemporaryMessage(withdrawMessage, "Not enough points.", 'error');
                tg.HapticFeedback.notificationOccurred('error');
                return;
            }
            withdrawAmountDisplay.textContent = formatPoints(pointsToWithdraw);
            withdrawPointsAmountInput.value = pointsToWithdraw;
            withdrawForm.style.display = 'block';
            withdrawDetailsInput.value = ''; // Clear previous details
            withdrawMessage.textContent = "";
            withdrawMethodSelect.dispatchEvent(new Event('change')); // Trigger label update
        });
    });

    withdrawMethodSelect.addEventListener('change', () => {
        const placeholderText = withdrawMethodSelect.value === 'UPI' ? "Enter your UPI ID" : "Enter your Binance Pay ID";
        withdrawDetailsLabel.textContent = `${withdrawMethodSelect.value}:`;
        withdrawDetailsInput.placeholder = placeholderText;
    });

    submitWithdrawalBtn.addEventListener('click', async () => {
        const points = parseInt(withdrawPointsAmountInput.value);
        const method = withdrawMethodSelect.value;
        const details = withdrawDetailsInput.value.trim();

        if (!details) {
            showTemporaryMessage(withdrawMessage, `Please enter your ${method} details.`, 'error');
            return;
        }

        submitWithdrawalBtn.disabled = true;
        showTemporaryMessage(withdrawMessage, "Processing withdrawal...", 'neutral', 0);

        const response = await apiCall('request_withdrawal', {
            points_withdrawn: points,
            method: method,
            details: details
        });

        if (response.success) {
            showTemporaryMessage(withdrawMessage, response.message || "Withdrawal request submitted!", 'success');
            tg.HapticFeedback.notificationOccurred('success');
            if (response.data) {
                userData = response.data;
                updateAllUI();
            }
            withdrawForm.style.display = 'none';
        } else {
            showTemporaryMessage(withdrawMessage, response.message || "Withdrawal failed.", 'error');
            tg.HapticFeedback.notificationOccurred('error');
        }
        submitWithdrawalBtn.disabled = false;
    });

    function showLoader(show) {
        loader.style.display = show ? 'flex' : 'none';
    }
    
    let messageTimeout = null;
    function showTemporaryMessage(element, message, type = 'neutral', duration = 3000) {
        if (!element) return;
        element.textContent = message;
        element.className = 'message'; // Reset classes
        if (type === 'success') element.classList.add('success');
        else if (type === 'error') element.classList.add('error');
        
        if (messageTimeout) clearTimeout(messageTimeout);
        if (duration > 0) { // If duration is 0, message stays until replaced
            messageTimeout = setTimeout(() => {
                element.textContent = "";
                element.className = 'message'; // Clear type classes
            }, duration);
        }
    }

    function handleError(message, isFatal = false) {
        console.error("App Error:", message);
        showLoader(false); // Always hide loader on error
        if (isFatal) {
            appContainer.innerHTML = `<p style='text-align:center; padding: 50px 20px; color: red;'>Error: ${message}<br><br>Please try restarting the app. If the problem persists, contact support.</p>`;
            appContainer.style.display = 'block'; // Ensure it's visible
        } else {
            // For non-fatal, maybe show in a specific error div or use a toast-like notification
            // For now, we'll rely on console and specific message elements like tapMessage, adStatusMessage etc.
            // If a general non-fatal error display is needed:
            // const generalErrorDiv = document.getElementById('general-error-display');
            // if(generalErrorDiv) showTemporaryMessage(generalErrorDiv, message, 'error', 5000);
        }
    }

    initializeApp();
});
