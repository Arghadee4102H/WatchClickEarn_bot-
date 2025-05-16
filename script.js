document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.ready();
    tg.expand(); // Expand the Web App to full height

    const API_URL = 'api.php';

    // UI Elements
    const loader = document.getElementById('loader');
    const appContainer = document.getElementById('app-container');
    const pages = document.querySelectorAll('.page');
    const navButtons = document.querySelectorAll('#bottom-nav button');

    const usernameDisplay = document.getElementById('username-display');
    const pointsDisplay = document.getElementById('points-display');
    const energyValueDisplay = document.getElementById('energy-value');
    const maxEnergyValueDisplay = document.getElementById('max-energy-value');
    const energyFill = document.getElementById('energy-fill');

    // Profile Page
    const profileName = document.getElementById('profile-name');
    const profileUserid = document.getElementById('profile-userid');
    const profileJoined = document.getElementById('profile-joined');
    const profilePoints = document.getElementById('profile-points');
    const profileReferralLink = document.getElementById('profile-referral-link');
    const copyReferralLinkBtn = document.getElementById('copy-referral-link');
    const profileTotalReferrals = document.getElementById('profile-total-referrals');

    // Tap Page
    const tapCatImage = document.getElementById('tap-cat-image');
    const dailyClicksDisplay = document.getElementById('daily-clicks-display');
    const tapFeedback = document.getElementById('tap-feedback');

    // Task Page
    const taskListDiv = document.getElementById('task-list');
    const completeAllTasksBtn = document.getElementById('complete-all-tasks-btn');
    const taskFeedback = document.getElementById('task-feedback');
    const totalTaskRewardDisplay = document.getElementById('total-task-reward');


    // Ads Page
    const adsWatchedTodayDisplay = document.getElementById('ads-watched-today');
    const watchAdBtn = document.getElementById('watch-ad-btn');
    const adCooldownTimerDisplay = document.getElementById('ad-cooldown-timer');
    const adsFeedback = document.getElementById('ads-feedback');

    // Withdraw Page
    const withdrawCurrentPoints = document.getElementById('withdraw-current-points');
    const withdrawForm = document.getElementById('withdraw-form');
    const withdrawFeedback = document.getElementById('withdraw-feedback');

    let userData = null;
    let energyInterval = null;
    let adCooldownInterval = null;
    const AD_COOLDOWN_SECONDS = 3 * 60; // 3 minutes
    let lastAdWatchedTimestamp = 0; // Timestamp of last ad watched successfully

    const BOT_USERNAME = "WatchClickEarn_bot"; // Your bot's username

    // --- Initialization ---
    async function initializeApp() {
        try {
            const initData = tg.initDataUnsafe;
            const telegramUser = initData.user;

            if (!telegramUser) {
                showError("Could not retrieve Telegram user data. Please try reopening.");
                tg.close();
                return;
            }

            const response = await fetchAPI('initializeUser', {
                telegram_id: telegramUser.id,
                username: telegramUser.username || '',
                first_name: telegramUser.first_name || '',
                start_param: initData.start_param || null
            });

            if (response.error) {
                showError(response.error);
                // Optionally, provide a way for user to retry or inform them.
                // For now, just log and stop further execution for this session.
                console.error("Initialization failed:", response.error);
                loader.textContent = `Error: ${response.error}`;
                return; // Stop if initialization fails
            }
            
            userData = response.data;
            updateUI();
            startEnergyRefill();
            loadTasks(); // Load tasks after user data is available
            checkAdCooldown(); // Initialize ad cooldown state

            loader.style.display = 'none';
            appContainer.style.display = 'flex';

        } catch (error) {
            console.error('Initialization error:', error);
            showError('Failed to initialize the app. Please try again.');
            loader.textContent = 'Initialization Error. Please reload.';
        }
    }

    // --- API Helper ---
async function fetchAPI(action, data = {}) {
    try {
        const response = await fetch(`${API_URL}?action=${action}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ...data, telegram_id: userData ? userData.telegram_id : (tg.initDataUnsafe.user ? tg.initDataUnsafe.user.id : null) })
        });
        if (!response.ok) {
            // --- MODIFIED SECTION START ---
            let errorText = await response.text(); // Get raw text first
            let errorData;
            try {
                errorData = JSON.parse(errorText); // Try to parse as JSON
            } catch (e) {
                // If not JSON, it might be a PHP error string
                errorData = { error: "SERVER_RESPONSE_NOT_JSON", details: errorText.substring(0, 500) }; // Show first 500 chars
            }
            console.error(`API Error (${response.status}):`, errorData);
            let displayError = errorData.message || errorData.error || `Server status ${response.status}`;
            if (errorData.debug_message) displayError += ` (Debug: ${errorData.debug_message})`;
            if (errorData.details) displayError += ` (Details: ${errorData.details})`;
            return { error: displayError };
            // --- MODIFIED SECTION END ---
        }
        return await response.json();
    } catch (error) {
        console.error('Fetch API error:', error);
        // Make sure 'error' object is stringified or handled if it's complex
        let errorMessage = 'Network error or server unreachable.';
        if (error && error.message) {
            errorMessage = `Network Error: ${error.message}`;
        } else if (typeof error === 'object') {
            errorMessage = `Network Error: ${JSON.stringify(error)}`;
        }
        return { error: errorMessage };
    }
}

    // --- UI Updates ---
    function updateUI() {
        if (!userData) return;

        usernameDisplay.textContent = userData.first_name || userData.username || 'Player';
        pointsDisplay.textContent = formatPoints(userData.points);

        // Energy
        energyValueDisplay.textContent = Math.floor(userData.energy);
        maxEnergyValueDisplay.textContent = userData.max_energy;
        energyFill.style.width = `${(userData.energy / userData.max_energy) * 100}%`;

        // Profile
        profileName.textContent = `${userData.first_name || ''} ${userData.last_name || ''}`.trim() || userData.username || 'N/A';
        profileUserid.textContent = userData.user_id;
        profileJoined.textContent = new Date(userData.created_at).toLocaleDateString();
        profilePoints.textContent = formatPoints(userData.points);
        profileReferralLink.value = `https://t.me/${BOT_USERNAME}?start=${userData.telegram_id}`;
        profileTotalReferrals.textContent = userData.total_referrals;

        // Tap
        dailyClicksDisplay.textContent = `${userData.daily_clicks_count} / ${userData.max_daily_clicks}`;
        tapCatImage.style.pointerEvents = (userData.energy >= userData.energy_per_tap && userData.daily_clicks_count < userData.max_daily_clicks) ? 'auto' : 'none';
        tapCatImage.style.opacity = (userData.energy >= userData.energy_per_tap && userData.daily_clicks_count < userData.max_daily_clicks) ? '1' : '0.5';


        // Ads
        adsWatchedTodayDisplay.textContent = `${userData.ads_watched_today} / ${userData.max_daily_ads}`;
        withdrawCurrentPoints.textContent = formatPoints(userData.points);

        // Update task completion status display
        updateTaskCompletionDisplay();
    }

    function formatPoints(points) {
        return parseInt(points).toLocaleString();
    }

    function showFeedback(element, message, type = 'success') {
        element.textContent = message;
        element.className = `feedback ${type}`;
        setTimeout(() => { element.textContent = ''; element.className = 'feedback'; }, 3000);
    }
    function showError(message) {
        // Could use a more prominent global error display
        console.error("App Error:", message);
        // For now, use a simple alert or update loader text if still visible
        if (loader.style.display !== 'none') {
            loader.textContent = `Error: ${message}`;
        } else {
            // A more robust solution would be a dedicated error display area in the app
            alert(`Error: ${message}`);
        }
    }


    // --- Navigation ---
    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetPageId = button.getAttribute('data-page');
            pages.forEach(page => page.classList.remove('active'));
            document.getElementById(targetPageId).classList.add('active');
            navButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');

            // Refresh data if needed when switching to certain pages
            if (targetPageId === 'profile-page' || targetPageId === 'withdraw-page') {
                fetchAndUpdateUserData(); // Ensure latest points are shown
            }
            if (targetPageId === 'task-page') {
                loadTasks(); // Refresh task status
            }
        });
    });

    async function fetchAndUpdateUserData() {
        if (!tg.initDataUnsafe.user) return;
        const response = await fetchAPI('getUserData', { telegram_id: tg.initDataUnsafe.user.id });
        if (response.data) {
            userData = response.data;
            updateUI();
        } else if (response.error) {
            showError(`Failed to update user data: ${response.error}`);
        }
    }


    // --- Energy Management ---
    function startEnergyRefill() {
        if (energyInterval) clearInterval(energyInterval);
        energyInterval = setInterval(async () => {
            if (userData.energy < userData.max_energy) {
                // Calculate energy gained since last server update to avoid client-only accumulation discrepancies
                const serverSyncResponse = await fetchAPI('syncEnergy'); // This endpoint will calculate and return the true energy
                if (serverSyncResponse.data && serverSyncResponse.data.energy !== undefined) {
                    userData.energy = serverSyncResponse.data.energy;
                    userData.points = serverSyncResponse.data.points; // Also sync points
                    userData.last_energy_update_ts = serverSyncResponse.data.last_energy_update_ts;
                } else {
                    // Fallback to client-side increment if sync fails, but this is less accurate
                    userData.energy = Math.min(userData.max_energy, userData.energy + 1);
                }
                updateUI();
            } else {
                // Energy is full, no need to poll server as frequently for energy.
                // Could slow down the interval or stop it until energy is spent.
            }
        }, userData.energy_refill_rate_seconds * 1000); // Use server-defined refill rate
    }


    // --- Tap Functionality ---
    tapCatImage.addEventListener('click', async () => {
        if (userData.energy < userData.energy_per_tap) {
            showFeedback(tapFeedback, 'Not enough energy!', 'error');
            return;
        }
        if (userData.daily_clicks_count >= userData.max_daily_clicks) {
            showFeedback(tapFeedback, 'Daily click limit reached!', 'error');
            return;
        }

        // Optimistic UI update
        userData.energy -= userData.energy_per_tap;
        userData.points += 1; // Assuming 1 point per tap, adjust if different
        userData.daily_clicks_count += 1;
        updateUI();
        
        // Visual feedback for tap
        animateTap(tapCatImage);

        const response = await fetchAPI('tap');
        if (response.error) {
            showFeedback(tapFeedback, response.error, 'error');
            // Revert optimistic update if server fails
            fetchAndUpdateUserData(); // Get latest state from server
        } else if (response.data) {
            userData = response.data; // Update with authoritative server data
            updateUI();
            // showFeedback(tapFeedback, `+${response.data.points_earned_this_tap || 1} point!`, 'success');
        }
    });

    function animateTap(element) {
        // Add a small visual effect on tap, like a quick scale or a particle
        const scoreIndicator = document.createElement('div');
        scoreIndicator.textContent = `+1`; // Or points earned
        scoreIndicator.style.position = 'absolute';
        scoreIndicator.style.left = `${element.offsetLeft + element.offsetWidth / 2 - 10}px`;
        scoreIndicator.style.top = `${element.offsetTop}px`;
        scoreIndicator.style.color = 'var(--theme-blue)';
        scoreIndicator.style.fontWeight = 'bold';
        scoreIndicator.style.fontSize = '1.5em';
        scoreIndicator.style.pointerEvents = 'none';
        scoreIndicator.style.animation = 'flyUp 0.7s ease-out forwards';
        document.getElementById('tap-page').appendChild(scoreIndicator);

        setTimeout(() => {
            scoreIndicator.remove();
        }, 700);
    }
    // CSS for flyUp animation (add to style.css or in <style> tag)
    const styleSheet = document.createElement("style");
    styleSheet.type = "text/css";
    styleSheet.innerText = `@keyframes flyUp { 0% { transform: translateY(0); opacity: 1; } 100% { transform: translateY(-50px); opacity: 0; } }`;
    document.head.appendChild(styleSheet);


    // --- Profile Page ---
    copyReferralLinkBtn.addEventListener('click', () => {
        profileReferralLink.select();
        document.execCommand('copy');
        tg.HapticFeedback.notificationOccurred('success');
        showFeedback(document.getElementById('profile-page').querySelector('.feedback') || tapFeedback, 'Referral link copied!', 'success');
    });

    // --- Task Functionality ---
    let currentTasks = [];
    async function loadTasks() {
        const response = await fetchAPI('getTasks');
        if (response.error) {
            showFeedback(taskFeedback, response.error, 'error');
            return;
        }
        currentTasks = response.data.tasks;
        userData.tasks_completed_today = response.data.tasks_completed_today; // boolean
        
        let totalReward = 0;
        taskListDiv.innerHTML = '';
        currentTasks.forEach(task => {
            totalReward += task.points_reward;
            const taskItem = document.createElement('div');
            taskItem.className = 'task-item';
            taskItem.innerHTML = `
                <div>
                    <h3>${task.name}</h3>
                    <p>${task.description} - ${task.points_reward} Points</p>
                </div>
                <a href="${task.link}" target="_blank" class="task-link-btn">Go to Task</a>
            `;
            taskListDiv.appendChild(taskItem);
        });
        totalTaskRewardDisplay.textContent = totalReward;
        updateTaskCompletionDisplay();
    }

    function updateTaskCompletionDisplay() {
         if (userData.tasks_completed_today) {
            completeAllTasksBtn.textContent = 'Tasks Completed Today!';
            completeAllTasksBtn.disabled = true;
            taskFeedback.textContent = `You've already earned points for tasks today. Check back tomorrow!`;
            taskFeedback.className = 'feedback success';
        } else {
            completeAllTasksBtn.textContent = "I've Joined All Channels/Groups!";
            completeAllTasksBtn.disabled = false;
            taskFeedback.textContent = '';
        }
    }

    completeAllTasksBtn.addEventListener('click', async () => {
        if (userData.tasks_completed_today) {
            showFeedback(taskFeedback, 'You have already completed tasks today.', 'error');
            return;
        }
        // Basic confirmation, ideally, Telegram API could verify channel joins, but that's complex.
        // For now, we trust the user action.
        tg.showConfirm("Have you joined all the listed channels and groups?", async (confirmed) => {
            if (confirmed) {
                const response = await fetchAPI('completeDailyTasks');
                if (response.error) {
                    showFeedback(taskFeedback, response.error, 'error');
                } else {
                    userData.points = response.data.new_total_points;
                    userData.tasks_completed_today = true;
                    updateUI(); // Reflects new points and task status
                    showFeedback(taskFeedback, `Tasks completed! +${response.data.points_earned} points.`, 'success');
                    tg.HapticFeedback.notificationOccurred('success');
                }
            }
        });
    });

    // --- Ads Functionality ---
    watchAdBtn.addEventListener('click', () => {
        if (userData.ads_watched_today >= userData.max_daily_ads) {
            showFeedback(adsFeedback, 'Daily ad limit reached.', 'error');
            return;
        }

        const now = Math.floor(Date.now() / 1000);
        if (now < lastAdWatchedTimestamp + AD_COOLDOWN_SECONDS) {
            showFeedback(adsFeedback, `Please wait for cooldown.`, 'error');
            return;
        }
        
        watchAdBtn.disabled = true;
        showFeedback(adsFeedback, 'Loading ad...', 'info');

        // Using Monetag Rewarded Interstitial as per example
        // Adjust if you use 'pop' version: show_9321934('pop').then(...).catch(...)
        show_9321934().then(async () => {
            // User watched the ad
            showFeedback(adsFeedback, 'Ad watched! Processing reward...', 'success');
            const response = await fetchAPI('watchAd');
            if (response.error) {
                showFeedback(adsFeedback, response.error, 'error');
            } else {
                userData = response.data; // Update user data with new points and ad count
                updateUI();
                lastAdWatchedTimestamp = Math.floor(Date.now() / 1000);
                localStorage.setItem('lastAdWatchedTimestamp', lastAdWatchedTimestamp);
                checkAdCooldown();
                showFeedback(adsFeedback, `+${response.data.points_earned_for_ad} points for watching the ad!`, 'success');
                tg.HapticFeedback.notificationOccurred('success');
            }
            watchAdBtn.disabled = false;
        }).catch(async e => {
            // Error or ad closed early
            console.warn('Ad display error or closed:', e);
            showFeedback(adsFeedback, 'Ad not completed or error occurred.', 'error');
            watchAdBtn.disabled = false;
            // Fetch latest user data in case server state changed or to prevent abuse
            await fetchAndUpdateUserData(); 
            checkAdCooldown(); // Reset cooldown if ad failed to prevent soft lock
        });
    });
    
    function checkAdCooldown() {
        if (adCooldownInterval) clearInterval(adCooldownInterval);

        const storedTimestamp = parseInt(localStorage.getItem('lastAdWatchedTimestamp')) || 0;
        if (storedTimestamp > lastAdWatchedTimestamp) lastAdWatchedTimestamp = storedTimestamp;

        adCooldownInterval = setInterval(() => {
            const now = Math.floor(Date.now() / 1000);
            const remainingCooldown = (lastAdWatchedTimestamp + AD_COOLDOWN_SECONDS) - now;

            if (userData.ads_watched_today >= userData.max_daily_ads) {
                adCooldownTimerDisplay.textContent = "Daily limit reached";
                watchAdBtn.disabled = true;
                clearInterval(adCooldownInterval);
                return;
            }

            if (remainingCooldown > 0) {
                const minutes = Math.floor(remainingCooldown / 60);
                const seconds = remainingCooldown % 60;
                adCooldownTimerDisplay.textContent = `${minutes}m ${seconds}s`;
                watchAdBtn.disabled = true;
            } else {
                adCooldownTimerDisplay.textContent = "Ready";
                watchAdBtn.disabled = false;
                clearInterval(adCooldownInterval);
            }
        }, 1000);
    }


    // --- Withdraw Functionality ---
    withdrawForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(withdrawForm);
        const amount = parseInt(formData.get('withdraw_amount'));
        const method = formData.get('payment_method');
        const details = formData.get('payment_details');

        if (!amount || !method || !details) {
            showFeedback(withdrawFeedback, 'Please fill all fields.', 'error');
            return;
        }
        if (userData.points < amount) {
            showFeedback(withdrawFeedback, 'Not enough points.', 'error');
            return;
        }

        // Minimum withdrawal checks are implicitly handled by the radio button values
        // but good to double check server-side.

        const response = await fetchAPI('requestWithdrawal', { amount, method, details });
        if (response.error) {
            showFeedback(withdrawFeedback, response.error, 'error');
        } else {
            userData.points = response.data.new_total_points;
            updateUI();
            showFeedback(withdrawFeedback, 'Withdrawal request submitted successfully!', 'success');
            withdrawForm.reset();
            tg.HapticFeedback.notificationOccurred('success');
        }
    });


    // --- App Start ---
    initializeApp();
});
