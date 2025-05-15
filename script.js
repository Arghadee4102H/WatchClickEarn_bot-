// script.js
document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.expand(); // Expand the web app to full height

    // --- Globals ---
    const API_URL = 'api.php';
    let userData = null;
    let currentEnergyInterval = null;
    let adCooldownInterval = null;
    let monetagZoneId = '9321934'; // Your Monetag Zone ID

    // --- UI Elements ---
    const loader = document.getElementById('loader');
    const appContainer = document.getElementById('app-container');
    const pages = document.querySelectorAll('.page');
    const navButtons = document.querySelectorAll('.nav-btn');

    // Profile Page Elements
    const profileUsername = document.getElementById('profile-username');
    const profileTgId = document.getElementById('profile-tg-id');
    const profileUniqueId = document.getElementById('profile-unique-id');
    const profileJoinDate = document.getElementById('profile-join-date');
    const profilePoints = document.getElementById('profile-points');
    const profileReferralLink = document.getElementById('profile-referral-link');
    const copyReferralBtn = document.getElementById('copy-referral-btn');
    const profileTotalReferrals = document.getElementById('profile-total-referrals');
    const themeSelect = document.getElementById('theme-select');

    // Tap Page Elements
    const tapCat = document.getElementById('tap-cat');
    const catImage = document.getElementById('cat-image'); // For effects
    const tapPointsDisplay = document.getElementById('tap-points');
    const energyValueDisplay = document.getElementById('energy-value');
    const maxEnergyValueDisplay = document.getElementById('max-energy-value');
    const energyFill = document.getElementById('energy-fill');
    const dailyTapsLeftDisplay = document.getElementById('daily-taps-left');
    const tapMessage = document.getElementById('tap-message');

    // Task Page Elements
    const taskListDiv = document.getElementById('task-list');
    const taskMessage = document.getElementById('task-message');

    // Ads Page Elements
    const adsPointsReward = document.getElementById('ads-points-reward');
    const adsWatchedToday = document.getElementById('ads-watched-today');
    const adsMaxDaily = document.getElementById('ads-max-daily');
    const watchAdBtn = document.getElementById('watch-ad-btn');
    const adStatusMessage = document.getElementById('ad-status-message');
    const adCooldownTimerDisplay = document.getElementById('ad-cooldown-timer');

    // Withdraw Page Elements
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

    // --- Initialization ---
    async function initializeApp() {
        showLoader(true);
        try {
            if (!tg.initDataUnsafe || !tg.initDataUnsafe.user) {
                handleError("Telegram user data not available. Please open this app through Telegram.");
                showLoader(false);
                // Potentially hide app container or show a message
                appContainer.innerHTML = "<p style='text-align:center; padding-top: 50px;'>Error: Could not load user data. Ensure you are using the latest version of Telegram and try again.</p>";
                appContainer.style.display = 'block';
                return;
            }

            const tgUser = tg.initDataUnsafe.user;
            const startParam = tg.initDataUnsafe.start_param || null;

            // Backend call to login/register user
            const response = await apiCall('init_user', {
                telegram_user_id: tgUser.id,
                username: tgUser.username || null,
                first_name: tgUser.first_name || null,
                referred_by_app_id: startParam
            });

            if (response.success && response.data) {
                userData = response.data;
                localStorage.setItem('userData', JSON.stringify(userData)); // Cache user data
                updateAllUI();
                startEnergyRefill();
                loadTasks();
                checkAdCooldown(); // Check initial ad cooldown state
                navigateToPage(localStorage.getItem('currentPage') || 'profile-page');
                showLoader(false);
                appContainer.style.display = 'flex';
            } else {
                handleError(response.message || "Failed to initialize user.");
                showLoader(false);
                appContainer.innerHTML = `<p style='text-align:center; padding-top: 50px;'>Error: ${response.message || "Initialization failed."}. Please try again later.</p>`;
                appContainer.style.display = 'block';
            }
        } catch (error) {
            console.error("Initialization error:", error);
            handleError("An error occurred during initialization. Check console for details.");
            showLoader(false);
            appContainer.innerHTML = "<p style='text-align:center; padding-top: 50px;'>A critical error occurred. Please restart the app.</p>";
            appContainer.style.display = 'block';
        }
        loadTheme();
    }

    // --- API Helper ---
    async function apiCall(action, data = {}) {
        try {
            const params = new URLSearchParams();
            params.append('action', action);
            if (userData && userData.telegram_user_id) { // Send user ID if available for non-init actions
                params.append('telegram_user_id', userData.telegram_user_id);
            }
            for (const key in data) {
                params.append(key, data[key]);
            }

            const response = await fetch(API_URL, {
                method: 'POST',
                body: params
            });
            if (!response.ok) {
                // Try to parse error from server if JSON, otherwise use status text
                let errorMsg = `HTTP error! status: ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorMsg;
                } catch (e) { /* ignore parsing error */ }
                throw new Error(errorMsg);
            }
            return await response.json();
        } catch (error) {
            console.error(`API call failed for action ${action}:`, error);
            // Return a structured error to be handled by the caller
            return { success: false, message: error.message || "Network error or server is unreachable." };
        }
    }

    // --- UI Update Functions ---
    function updateAllUI() {
        if (!userData) return;
        updateProfileUI();
        updateTapUI();
        updateAdsUI(); // Also updates ads points reward
        updateWithdrawUI();
    }

    function updateProfileUI() {
        profileUsername.textContent = userData.username || userData.first_name || 'User';
        profileTgId.textContent = userData.telegram_user_id;
        profileUniqueId.textContent = userData.unique_app_id;
        profileJoinDate.textContent = new Date(userData.created_at).toLocaleDateString();
        profilePoints.textContent = formatPoints(userData.points);
        profileReferralLink.value = `https://t.me/WatchClickEarn_bot?start=${userData.unique_app_id}`; // Replace with your bot username
        profileTotalReferrals.textContent = userData.total_referrals_verified;
    }

    function updateTapUI() {
        tapPointsDisplay.textContent = formatPoints(userData.points);
        energyValueDisplay.textContent = Math.floor(userData.energy);
        maxEnergyValueDisplay.textContent = userData.max_energy;
        const energyPercentage = (userData.energy / userData.max_energy) * 100;
        energyFill.style.width = `${energyPercentage}%`;
        dailyTapsLeftDisplay.textContent = userData.max_daily_taps - userData.daily_taps;
    }

    function updateAdsUI() {
        adsPointsReward.textContent = userData.points_per_ad || 50;
        adsWatchedToday.textContent = userData.daily_ads_watched_count;
        adsMaxDaily.textContent = userData.max_daily_ads;
    }

    function updateWithdrawUI() {
        withdrawCurrentPoints.textContent = formatPoints(userData.points);
    }

    function formatPoints(points) {
        return Number(points).toLocaleString();
    }

    // --- Navigation ---
    function navigateToPage(pageId) {
        pages.forEach(page => page.classList.remove('active'));
        navButtons.forEach(btn => btn.classList.remove('active'));

        const targetPage = document.getElementById(pageId);
        const targetButton = document.querySelector(`.nav-btn[data-page="${pageId}"]`);

        if (targetPage) targetPage.classList.add('active');
        if (targetButton) targetButton.classList.add('active');
        localStorage.setItem('currentPage', pageId); // Remember last page
    }

    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            navigateToPage(button.dataset.page);
        });
    });

    // --- Theme Switcher ---
    function applyTheme(themeName) {
        document.body.className = `theme-${themeName}`;
        localStorage.setItem('theme', themeName);
        themeSelect.value = themeName; // Sync dropdown
        tg.setHeaderColor(getThemeColor(themeName, 'nav-bg')); // Optional: Sync Telegram header
        tg.setBackgroundColor(getThemeColor(themeName, 'bg-color')); // Optional: Sync Telegram background
    }

    function loadTheme() {
        const savedTheme = localStorage.getItem('theme') || 'light';
        applyTheme(savedTheme);
    }

    themeSelect.addEventListener('change', (e) => {
        applyTheme(e.target.value);
    });
    
    function getThemeColor(themeName, colorType) {
        // Simplified: assumes CSS vars are accessible or hardcode main colors
        if (themeName === 'dark') return colorType === 'nav-bg' ? '#1e1e1e' : '#121212';
        if (themeName === 'blue') return colorType === 'nav-bg' ? '#b3e5fc' : '#e0f2f7';
        return colorType === 'nav-bg' ? '#f8f9fa' : '#ffffff'; // Light
    }


    // --- Profile Page Logic ---
    copyReferralBtn.addEventListener('click', () => {
        profileReferralLink.select();
        try {
            document.execCommand('copy');
            alert('Referral link copied!');
        } catch (err) {
            alert('Failed to copy link. Please copy manually.');
        }
         // For Telegram Web Apps, a more native way might be available or just show a success message
        tg.HapticFeedback.notificationOccurred('success');
    });


    // --- Tap Page Logic ---
    let lastTapTime = 0;
    const TAP_DEBOUNCE_MS = 100; // Minimum time between client-side taps

    tapCat.addEventListener('click', async () => {
        const now = Date.now();
        if (now - lastTapTime < TAP_DEBOUNCE_MS) {
            // tapMessage.textContent = "Tapping too fast!";
            return; // Debounce
        }
        lastTapTime = now;

        // Client-side optimistic update
        if (userData.energy >= userData.energy_per_tap && (userData.max_daily_taps - userData.daily_taps) > 0) {
            // userData.energy -= userData.energy_per_tap; // Optimistic decrement
            // userData.daily_taps++; // Optimistic increment
            // updateTapUI(); // Update UI immediately

            // Visual feedback
            catImage.style.transform = 'scale(0.9)';
            setTimeout(() => catImage.style.transform = 'scale(1)', 100);
            tg.HapticFeedback.impactOccurred('light');


            const response = await apiCall('tap', { current_utc_time: new Date().toISOString() });
            if (response.success && response.data) {
                userData = response.data; // Update with server truth
                updateAllUI(); // Full UI update based on server response
                tapMessage.textContent = response.message || `+${response.points_earned || 0} Points!`;
            } else {
                // Revert optimistic update if server fails or denies
                // This might involve fetching fresh user data if complex
                // For now, just show server message
                tapMessage.textContent = response.message || "Tap failed.";
                if (response.data) { // If server sent updated data despite "failure" (e.g. out of energy)
                    userData = response.data;
                    updateAllUI();
                }
            }
        } else if (userData.energy < userData.energy_per_tap) {
            tapMessage.textContent = "Not enough energy!";
        } else {
            tapMessage.textContent = "Daily tap limit reached!";
        }
        setTimeout(() => tapMessage.textContent = "", 2000);
    });

    function startEnergyRefill() {
        if (currentEnergyInterval) clearInterval(currentEnergyInterval);
        currentEnergyInterval = setInterval(async () => {
            if (userData && userData.energy < userData.max_energy) {
                // Client-side visual refill (minor increment)
                // The server recalculates authoritatively on next action or periodic sync
                const timeSinceLastUpdate = (Date.now() - new Date(userData.last_energy_update_ts).getTime()) / 1000;
                const energyToRefill = Math.floor(timeSinceLastUpdate / userData.energy_refill_rate_seconds);

                if (energyToRefill > 0) {
                     // More robust: just call sync_user_data to get latest from server
                    const response = await apiCall('sync_user_data');
                    if (response.success && response.data) {
                        userData = response.data;
                        updateAllUI();
                    }
                }
            }
        }, 5000); // Check for refill every 5 seconds
    }


    // --- Task Page Logic ---
    async function loadTasks() {
        const response = await apiCall('get_tasks');
        taskListDiv.innerHTML = ''; // Clear previous tasks
        if (response.success && response.tasks) {
            if (response.tasks.length === 0) {
                 taskListDiv.innerHTML = '<p>No tasks available at the moment. Check back later!</p>';
                 return;
            }
            response.tasks.forEach(task => {
                const taskItem = document.createElement('div');
                taskItem.classList.add('task-item');
                if (task.completed_today) {
                    taskItem.classList.add('completed');
                }
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

            document.querySelectorAll('.task-action button').forEach(button => {
                button.addEventListener('click', async (e) => {
                    if (button.disabled) return;
                    const taskId = e.target.dataset.taskId;
                    const taskLink = e.target.dataset.taskLink;

                    // Open link in Telegram's browser
                    tg.openLink(taskLink);

                    // Assume completion after clicking for simplicity.
                    // For real verification, you'd need more complex logic, possibly involving the bot.
                    // We can add a small delay and then try to mark as complete.
                    // Or better, let user confirm. For now, auto-complete after a delay.
                    button.disabled = true;
                    button.textContent = 'Processing...';

                    // Give some time for user to interact with the link.
                    // This is a UX choice. Real verification is hard.
                    await new Promise(resolve => setTimeout(resolve, 3000)); // 3 sec delay

                    const completeResponse = await apiCall('complete_task', { task_id: taskId });
                    if (completeResponse.success) {
                        taskMessage.textContent = completeResponse.message || `Task ${taskId} completed! +${completeResponse.points_earned} points.`;
                        userData = completeResponse.data; // Update user data
                        updateAllUI();
                        loadTasks(); // Refresh task list
                    } else {
                        taskMessage.textContent = completeResponse.message || "Failed to complete task.";
                        button.disabled = false; // Re-enable if failed
                        button.textContent = 'Go to Task';
                    }
                    setTimeout(() => taskMessage.textContent = "", 3000);
                });
            });
        } else {
            taskListDiv.innerHTML = `<p>${response.message || 'Could not load tasks.'}</p>`;
        }
    }

    // --- Ads Page Logic ---
    function checkAdCooldown() {
        if (!userData || !userData.last_ad_watched_ts) {
            adCooldownTimerDisplay.textContent = "Ready";
            watchAdBtn.disabled = false;
            return;
        }

        const lastAdTime = new Date(userData.last_ad_watched_ts).getTime();
        const adCooldownSeconds = userData.ad_cooldown_seconds || (3 * 60); // Default 3 minutes
        const now = Date.now();
        const timePassed = (now - lastAdTime) / 1000;

        if (timePassed < adCooldownSeconds) {
            watchAdBtn.disabled = true;
            let timeLeft = Math.ceil(adCooldownSeconds - timePassed);
            if (adCooldownInterval) clearInterval(adCooldownInterval);
            adCooldownInterval = setInterval(() => {
                if (timeLeft <= 0) {
                    clearInterval(adCooldownInterval);
                    adCooldownTimerDisplay.textContent = "Ready";
                    watchAdBtn.disabled = false;
                } else {
                    adCooldownTimerDisplay.textContent = `${Math.floor(timeLeft / 60)}m ${timeLeft % 60}s`;
                    timeLeft--;
                }
            }, 1000);
        } else {
            adCooldownTimerDisplay.textContent = "Ready";
            watchAdBtn.disabled = false;
        }
    }

    watchAdBtn.addEventListener('click', () => {
        if (userData.daily_ads_watched_count >= userData.max_daily_ads) {
            adStatusMessage.textContent = "Daily ad limit reached.";
            setTimeout(() => adStatusMessage.textContent = "", 3000);
            return;
        }
        
        watchAdBtn.disabled = true;
        adStatusMessage.textContent = "Loading ad...";

        if (typeof show_9321934 === 'function') {
            show_9321934() // Using Rewarded Interstitial as per user's code
                .then(async () => {
                    adStatusMessage.textContent = "Ad watched! Claiming reward...";
                    tg.HapticFeedback.notificationOccurred('success');
                    
                    const response = await apiCall('watched_ad');
                    if (response.success && response.data) {
                        userData = response.data;
                        updateAllUI();
                        checkAdCooldown(); // Re-check cooldown (should be active now)
                        adStatusMessage.textContent = response.message || `+${response.points_earned} points!`;
                    } else {
                        adStatusMessage.textContent = response.message || "Failed to record ad view.";
                    }
                })
                .catch(e => {
                    console.error("Monetag Ad Error:", e);
                    adStatusMessage.textContent = "Ad failed to load or was closed early.";
                    // Re-enable button only if error suggests it (e.g., not a limit)
                    // For simplicity, let cooldown logic handle re-enabling or keep it disabled
                    // until cooldown passes or page reloads.
                     watchAdBtn.disabled = false; // Or rely on checkAdCooldown
                     checkAdCooldown(); // Better to rely on this
                })
                .finally(() => {
                    setTimeout(() => adStatusMessage.textContent = "", 3000);
                });
        } else {
            adStatusMessage.textContent = "Ad SDK not available.";
            console.error("Monetag function show_9321934 not found.");
            watchAdBtn.disabled = false; // Re-enable if SDK is missing
        }
    });


    // --- Withdraw Page Logic ---
    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            const pointsToWithdraw = parseInt(button.dataset.points);
            if (userData.points < pointsToWithdraw) {
                withdrawMessage.textContent = "Not enough points for this option.";
                tg.HapticFeedback.notificationOccurred('error');
                setTimeout(() => withdrawMessage.textContent = "", 3000);
                return;
            }
            withdrawAmountDisplay.textContent = formatPoints(pointsToWithdraw);
            withdrawPointsAmountInput.value = pointsToWithdraw;
            withdrawForm.style.display = 'block';
            withdrawMessage.textContent = ""; // Clear previous messages
        });
    });

    withdrawMethodSelect.addEventListener('change', () => {
        if (withdrawMethodSelect.value === 'UPI') {
            withdrawDetailsLabel.textContent = "UPI ID:";
            withdrawDetailsInput.placeholder = "Enter your UPI ID";
        } else if (withdrawMethodSelect.value === 'Binance') {
            withdrawDetailsLabel.textContent = "Binance Pay ID:";
            withdrawDetailsInput.placeholder = "Enter your Binance Pay ID";
        }
    });

    submitWithdrawalBtn.addEventListener('click', async () => {
        const points = parseInt(withdrawPointsAmountInput.value);
        const method = withdrawMethodSelect.value;
        const details = withdrawDetailsInput.value.trim();

        if (!details) {
            withdrawMessage.textContent = "Please enter your payment details.";
            return;
        }

        submitWithdrawalBtn.disabled = true;
        withdrawMessage.textContent = "Processing withdrawal...";

        const response = await apiCall('request_withdrawal', {
            points_withdrawn: points,
            method: method,
            details: details
        });

        if (response.success) {
            withdrawMessage.textContent = response.message || "Withdrawal request submitted successfully!";
            tg.HapticFeedback.notificationOccurred('success');
            if (response.data) { // Server should return updated user data
                userData = response.data;
                updateAllUI();
            }
            withdrawForm.style.display = 'none'; // Hide form on success
            withdrawDetailsInput.value = ''; // Clear details
        } else {
            withdrawMessage.textContent = response.message || "Withdrawal request failed.";
            tg.HapticFeedback.notificationOccurred('error');
        }
        setTimeout(() => withdrawMessage.textContent = "", 5000);
        submitWithdrawalBtn.disabled = false;
    });


    // --- Helper Functions ---
    function showLoader(show) {
        loader.style.display = show ? 'flex' : 'none';
    }

    function handleError(message) {
        console.error("Error:", message);
        // You could display this in a more prominent way if needed
        // For now, console log is primary for non-user-facing errors
    }

    // --- Start the app ---
    initializeApp();

    // Expose some functions for debugging if needed (remove for production)
    // window.app = { tg, userData, apiCall, updateAllUI, loadTasks };
});
