document.addEventListener('DOMContentLoaded', () => {
    const tg = window.Telegram.WebApp;
    tg.expand(); // Expand the Web App to full height

    const API_URL = 'api.php';

    // DOM Elements
    const loader = document.getElementById('loader');
    const appContainer = document.getElementById('app');
    const mainContent = document.getElementById('mainContent');
    const pointsDisplay = document.getElementById('pointsDisplay');
    const energyDisplay = document.getElementById('energyDisplay');
    const energyBar = document.getElementById('energyBar');

    // Page sections
    const pages = {
        profile: document.getElementById('profilePage'),
        tap: document.getElementById('tapPage'),
        tasks: document.getElementById('tasksPage'),
        ads: document.getElementById('adsPage'),
        withdraw: document.getElementById('withdrawPage')
    };

    // Nav buttons
    const navButtons = document.querySelectorAll('.nav-button');

    // Profile Page Elements
    const profileName = document.getElementById('profileName');
    const profileUserId = document.getElementById('profileUserId');
    const profileJoinDate = document.getElementById('profileJoinDate');
    const profileTotalPoints = document.getElementById('profileTotalPoints');
    const profileTotalReferrals = document.getElementById('profileTotalReferrals');
    const profileReferralLink = document.getElementById('profileReferralLink');
    const copyReferralLinkBtn = document.getElementById('copyReferralLink');

    // Tap Page Elements
    const tapImage = document.getElementById('tapImage');
    const ratImageContainer = document.getElementById('ratImageContainer');
    const clickFeedbackEl = document.getElementById('clickFeedback');
    const userClicksTodayDisplay = document.getElementById('userClicksToday');
    const maxClicksPerDayDisplay = document.getElementById('maxClicksPerDay');


    // Tasks Page Elements
    const taskListContainer = document.getElementById('taskList');

    // Ads Page Elements
    const watchAdButton = document.getElementById('watchAdButton');
    const adMessage = document.getElementById('adMessage');
    const pointsPerAdDisplay = document.getElementById('pointsPerAd');
    const pointsPerAdBtnDisplay = document.getElementById('pointsPerAdBtn');
    const maxAdsPerDayDisplay = document.getElementById('maxAdsPerDay');
    const maxAdsPerDayValDisplay = document.getElementById('maxAdsPerDayVal');
    const userAdsWatchedTodayDisplay = document.getElementById('userAdsWatchedToday');
    const adCooldownTimerDisplay = document.getElementById('adCooldownTimer');
    const cooldownTimeDisplay = document.getElementById('cooldownTime');


    // Withdraw Page Elements
    const withdrawPointsCurrent = document.getElementById('withdrawPointsCurrent');
    const withdrawButtons = document.querySelectorAll('.withdraw-button');
    const withdrawForm = document.getElementById('withdrawForm');
    const withdrawAmountSelected = document.getElementById('withdrawAmountSelected');
    const withdrawMethodSelect = document.getElementById('withdrawMethod');
    const withdrawDetailsLabel = document.getElementById('withdrawDetailsLabel');
    const withdrawDetailsInput = document.getElementById('withdrawDetailsInput');
    const submitWithdrawalButton = document.getElementById('submitWithdrawalButton');
    const withdrawMessage = document.getElementById('withdrawMessage');

    let currentUserData = null;
    let energyInterval = null;
    let adCooldownInterval = null;

    // --- Helper Functions ---
    async function apiCall(action, data = {}) {
        try {
            const params = new URLSearchParams({ action, ...data });
            if (tg.initData) {
                params.append('telegram_init_data', tg.initData);
            }
            
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString()
            });
            if (!response.ok) {
                const errorText = await response.text();
                console.error('API Error:', response.status, errorText);
                showError(`Server error: ${response.status}. Please try again.`);
                return null;
            }
            return await response.json();
        } catch (error) {
            console.error('Fetch Error:', error);
            showError('Network error. Please check your connection.');
            return null;
        }
    }

    function showPage(pageId) {
        Object.values(pages).forEach(page => page.classList.remove('active'));
        if (pages[pageId]) {
            pages[pageId].classList.add('active');
        } else {
            pages.profile.classList.add('active'); // Default to profile
        }
        
        navButtons.forEach(button => {
            button.classList.remove('active');
            if (button.dataset.page === pageId + 'Page') {
                button.classList.add('active');
            }
        });
        // Refresh data if needed when switching pages
        if (pageId === 'tasks') loadTasks();
        if (pageId === 'profile' && currentUserData) updateProfileUI(currentUserData);
    }

    function updateUI(data) {
        if (!data) return;
        currentUserData = data; // Store current user data

        pointsDisplay.textContent = `Points: ${data.points.toLocaleString()}`;
        energyDisplay.textContent = `${data.energy}/${data.max_energy}`;
        energyBar.style.width = `${(data.energy / data.max_energy) * 100}%`;

        // Update profile page if it's active (or will be shown)
        updateProfileUI(data);
        
        // Update tap page specific UI
        userClicksTodayDisplay.textContent = data.click_count_today;
        maxClicksPerDayDisplay.textContent = data.max_clicks_per_day_config;
        
        // Update ads page specific UI
        pointsPerAdDisplay.textContent = data.points_per_ad_config;
        pointsPerAdBtnDisplay.textContent = data.points_per_ad_config;
        maxAdsPerDayDisplay.textContent = data.max_ads_per_day_config;
        maxAdsPerDayValDisplay.textContent = data.max_ads_per_day_config;
        userAdsWatchedTodayDisplay.textContent = data.ads_watched_today;
        
        // Update withdraw page
        withdrawPointsCurrent.textContent = data.points.toLocaleString();

        checkAdButtonStatus(data);
        manageEnergyRegeneration(data);
    }
    
    function updateProfileUI(data) {
        if (!data) return;
        profileName.textContent = data.first_name || data.username || 'N/A';
        profileUserId.textContent = data.telegram_id;
        const joinDate = new Date(data.join_date);
        profileJoinDate.textContent = joinDate.toLocaleDateString();
        profileTotalPoints.textContent = data.points.toLocaleString();
        profileTotalReferrals.textContent = data.total_referrals || 0;
        // Note: BOT_USERNAME will be hardcoded or fetched from config
        const referralLink = `https://t.me/${data.bot_username}?start=${data.telegram_id}`;
        profileReferralLink.value = referralLink;
    }


    function showError(message) {
        // A more sophisticated error display could be used
        alert(`Error: ${message}`);
        console.error(message);
    }
    
    function showClickFeedback(points, event) {
        const feedback = document.createElement('div');
        feedback.className = 'click-feedback';
        feedback.textContent = `+${points}`;
        
        // Position relative to the tap container
        const rect = ratImageContainer.getBoundingClientRect();
        // Adjust to be relative to the viewport if ratImageContainer is not the offset parent
        feedback.style.left = `${event.clientX - rect.left}px`;
        feedback.style.top = `${event.clientY - rect.top - 30}px`; // Move it up a bit from click

        ratImageContainer.appendChild(feedback);
        setTimeout(() => feedback.remove(), 600);
    }


    // --- Page Specific Logic ---

    // TAP PAGE
    async function handleTap(event) {
        if (!currentUserData || currentUserData.energy <= 0) {
            clickFeedbackEl.textContent = "No energy!";
            setTimeout(() => clickFeedbackEl.textContent = "", 1000);
            return;
        }
        if (currentUserData.click_count_today >= currentUserData.max_clicks_per_day_config) {
            clickFeedbackEl.textContent = "Daily click limit reached!";
            setTimeout(() => clickFeedbackEl.textContent = "", 2000);
            return;
        }

        // Optimistic UI update
        currentUserData.energy -= 1;
        currentUserData.points += currentUserData.points_per_tap_config; // Assuming 1 point per tap
        currentUserData.click_count_today += 1;
        updateUI(currentUserData);
        showClickFeedback(currentUserData.points_per_tap_config, event);

        const response = await apiCall('tap');
        if (response && response.success) {
            updateUI(response.data); // Sync with server state
        } else {
            // Revert optimistic update if API call fails
            // This can be complex, for now, just log or show generic error
            console.error("Tap failed to sync with server.");
            // Potentially reload user data to correct state
            loadInitialData(); 
        }
    }

    // TASKS PAGE
    async function loadTasks() {
        const response = await apiCall('get_tasks');
        if (response && response.success) {
            taskListContainer.innerHTML = ''; // Clear previous tasks
            if (response.tasks.length === 0) {
                taskListContainer.innerHTML = '<p>No tasks available at the moment.</p>';
                return;
            }
            response.tasks.forEach(task => {
                const taskItem = document.createElement('div');
                taskItem.className = 'task-item';
                taskItem.innerHTML = `
                    <div class="task-item-info">
                        <h4>${task.name} (${task.points_reward} Points)</h4>
                        <p>${task.description}</p>
                    </div>
                    <button class="task-button" data-task-id="${task.id}" data-task-link="${task.link}" ${task.completed_today ? 'disabled' : ''}>
                        ${task.completed_today ? 'Completed' : 'Go to Task'}
                    </button>
                `;
                taskListContainer.appendChild(taskItem);
            });
        } else {
            taskListContainer.innerHTML = '<p>Failed to load tasks. Please try again.</p>';
        }
    }

    taskListContainer.addEventListener('click', async (event) => {
        if (event.target.classList.contains('task-button') && !event.target.disabled) {
            const button = event.target;
            const taskId = button.dataset.taskId;
            const taskLink = button.dataset.taskLink;

            // Open task link in new tab
            tg.openLink(taskLink); 
            // tg.openTelegramLink(taskLink); // If it's a t.me link, this is better

            // Assume user completes it by opening. For actual verification, more complex logic is needed
            // (e.g., bot verifies channel join, then user clicks "I've completed")
            // For now, we'll mark as complete after a delay, simulating user action
            button.disabled = true;
            button.textContent = 'Checking...';

            setTimeout(async () => {
                const response = await apiCall('complete_task', { task_id: taskId });
                if (response && response.success) {
                    updateUI(response.data);
                    button.textContent = 'Completed';
                    // Optionally, reload tasks to reflect changes for all
                    // loadTasks(); 
                } else {
                    showError(response ? response.message : 'Failed to complete task.');
                    button.disabled = false;
                    button.textContent = 'Go to Task';
                }
            }, 5000); // 5 second delay to simulate user doing task
        }
    });

    // ADS PAGE
    async function handleWatchAd() {
        if (!currentUserData || currentUserData.ads_watched_today >= currentUserData.max_ads_per_day_config) {
            adMessage.textContent = 'Daily ad limit reached.';
            adMessage.className = 'message error';
            return;
        }
        
        if (currentUserData.ad_cooldown_active) {
             adMessage.textContent = `Please wait for cooldown. Next ad in ${formatTime(currentUserData.ad_cooldown_remaining_seconds)}.`;
             adMessage.className = 'message error';
             return;
        }

        watchAdButton.disabled = true;
        watchAdButton.textContent = 'Loading Ad...';

        try {
            // Monetag function. Ensure `show_9321934` is globally available from their SDK.
            // If it's not global by default, you might need `window.Monetag.show_9321934()` or similar.
            if (typeof show_9321934 === "function") {
                show_9321934().then(async () => {
                    // Ad watched successfully (or closed after interstitial shown)
                    adMessage.textContent = 'Ad viewed! Processing reward...';
                    adMessage.className = 'message';
                    const response = await apiCall('watch_ad');
                    if (response && response.success) {
                        updateUI(response.data);
                        adMessage.textContent = `+${response.data.points_per_ad_config} points added!`;
                        adMessage.className = 'message success';
                    } else {
                        adMessage.textContent = response ? response.message : 'Failed to process ad reward.';
                        adMessage.className = 'message error';
                    }
                    checkAdButtonStatus(response ? response.data : currentUserData); 
                }).catch(async e => {
                    // Error during ad play or user skipped (if skippable and Monetag treats as error)
                    console.error('Monetag ad error/skip:', e);
                    adMessage.textContent = 'Ad not completed or error. No points awarded.';
                    adMessage.className = 'message error';
                    // Even on error, update status from server to get latest cooldown info etc.
                    const latestData = await apiCall('get_user_data');
                    if (latestData && latestData.success) updateUI(latestData.data);
                    checkAdButtonStatus(latestData ? latestData.data : currentUserData);
                });
            } else {
                console.error("Monetag function show_9321934 not found.");
                adMessage.textContent = 'Ad provider not available. Please try again later.';
                adMessage.className = 'message error';
                checkAdButtonStatus(currentUserData);
            }
        } catch (e) {
            console.error("Error triggering ad:", e);
            adMessage.textContent = 'Could not show ad. Please try again later.';
            adMessage.className = 'message error';
            checkAdButtonStatus(currentUserData);
        }
    }
    
    function checkAdButtonStatus(data) {
        if (!data) return;
        currentUserData = data; // ensure current data is up to date

        if (data.ads_watched_today >= data.max_ads_per_day_config) {
            watchAdButton.disabled = true;
            watchAdButton.textContent = 'Daily Ad Limit Reached';
            adCooldownTimerDisplay.style.display = 'none';
        } else if (data.ad_cooldown_active && data.ad_cooldown_remaining_seconds > 0) {
            watchAdButton.disabled = true;
            watchAdButton.textContent = 'Ad Cooldown';
            adCooldownTimerDisplay.style.display = 'block';
            startAdCooldownTimer(data.ad_cooldown_remaining_seconds);
        } else {
            watchAdButton.disabled = false;
            watchAdButton.textContent = `Watch Ad (${data.points_per_ad_config} Points)`;
            adCooldownTimerDisplay.style.display = 'none';
            if (adCooldownInterval) clearInterval(adCooldownInterval);
        }
    }

    function startAdCooldownTimer(duration) {
        if (adCooldownInterval) clearInterval(adCooldownInterval);
        let timer = duration;
        cooldownTimeDisplay.textContent = formatTime(timer);
        adCooldownInterval = setInterval(() => {
            timer--;
            cooldownTimeDisplay.textContent = formatTime(timer);
            if (timer <= 0) {
                clearInterval(adCooldownInterval);
                adCooldownTimerDisplay.style.display = 'none';
                // Re-fetch user data to get fresh status from server
                // as client-side timer might not be perfectly synced
                // or another ad might have been watched on another device.
                loadInitialData(); 
            }
        }, 1000);
    }

    function formatTime(seconds) {
        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    }


    // WITHDRAW PAGE
    withdrawMethodSelect.addEventListener('change', (e) => {
        const method = e.target.value;
        if (method === 'UPI') {
            withdrawDetailsLabel.textContent = 'UPI ID:';
            withdrawDetailsInput.placeholder = 'Enter your UPI ID';
        } else if (method === 'Binance') {
            withdrawDetailsLabel.textContent = 'Binance Pay ID / Address:';
            withdrawDetailsInput.placeholder = 'Enter Binance ID or Address';
        }
    });

    withdrawButtons.forEach(button => {
        button.addEventListener('click', () => {
            const amount = parseInt(button.dataset.amount);
            if (currentUserData.points < amount) {
                withdrawMessage.textContent = 'Not enough points for this withdrawal.';
                withdrawMessage.className = 'message error';
                withdrawForm.style.display = 'none';
                return;
            }
            withdrawAmountSelected.textContent = amount.toLocaleString();
            withdrawForm.dataset.amount = amount; // Store amount in form dataset
            withdrawForm.style.display = 'block';
            withdrawMessage.textContent = '';
        });
    });

    submitWithdrawalButton.addEventListener('click', async () => {
        const amount = parseInt(withdrawForm.dataset.amount);
        const method = withdrawMethodSelect.value;
        const details = withdrawDetailsInput.value.trim();

        if (!details) {
            withdrawMessage.textContent = 'Please enter withdrawal details.';
            withdrawMessage.className = 'message error';
            return;
        }

        submitWithdrawalButton.disabled = true;
        submitWithdrawalButton.textContent = 'Processing...';

        const response = await apiCall('submit_withdrawal', {
            amount: amount,
            method: method,
            details: details
        });

        if (response && response.success) {
            updateUI(response.data);
            withdrawMessage.textContent = 'Withdrawal request submitted successfully!';
            withdrawMessage.className = 'message success';
            withdrawForm.style.display = 'none';
            withdrawDetailsInput.value = '';
        } else {
            withdrawMessage.textContent = response ? response.message : 'Withdrawal failed.';
            withdrawMessage.className = 'message error';
        }
        submitWithdrawalButton.disabled = false;
        submitWithdrawalButton.textContent = 'Submit Request';
    });

    // --- Energy Management ---
    function manageEnergyRegeneration(data) {
        if (energyInterval) clearInterval(energyInterval);
        if (data.energy < data.max_energy) {
            // Calculate time per energy point in ms
            const msPerEnergyPoint = (60 / data.energy_regen_rate_per_minute_config) * 1000;
            
            energyInterval = setInterval(async () => {
                // It's better to rely on server for actual energy state,
                // but for smoother UI, we can increment locally and sync periodically or on actions.
                // For this version, we will fetch user data to get regenerated energy.
                const refreshedData = await apiCall('get_user_data');
                if (refreshedData && refreshedData.success) {
                    updateUI(refreshedData.data);
                    if (refreshedData.data.energy >= refreshedData.data.max_energy) {
                        clearInterval(energyInterval);
                    }
                } else {
                     // If fetching fails, stop trying to prevent spamming server
                     clearInterval(energyInterval);
                }
            }, msPerEnergyPoint > 5000 ? msPerEnergyPoint : 5000); // Refresh at least every 5 seconds or per energy tick
        }
    }


    // --- Initialization ---
    async function loadInitialData() {
        loader.style.display = 'flex';
        appContainer.style.display = 'none';

        let startParam = null;
        if (tg.initDataUnsafe && tg.initDataUnsafe.start_param) {
            startParam = tg.initDataUnsafe.start_param;
        }
        
        const initialPayload = {};
        if (startParam) {
            initialPayload.start_param = startParam;
        }

        const response = await apiCall('init_user', initialPayload);
        if (response && response.success) {
            updateUI(response.data);
            showPage('profile'); // Default page
        } else {
            showError(response ? response.message : 'Failed to initialize. Please restart the app.');
            // Keep loader visible or show a specific error screen
            return; // Stop further execution if init fails
        }
        loader.style.display = 'none';
        appContainer.style.display = 'flex';
    }

    // Event Listeners
    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            const pageId = button.dataset.page.replace('Page', '');
            showPage(pageId);
        });
    });

    tapImage.addEventListener('click', handleTap);
    ratImageContainer.addEventListener('click', handleTap); // Also on wrapper for broader click area

    watchAdButton.addEventListener('click', handleWatchAd);
    
    copyReferralLinkBtn.addEventListener('click', () => {
        profileReferralLink.select();
        document.execCommand('copy');
        tg.showAlert('Referral link copied!');
    });


    // Apply Telegram theme variables
    function applyTelegramTheme() {
        const root = document.documentElement;
        if (tg.themeParams) {
            root.style.setProperty('--tg-theme-bg-color', tg.themeParams.bg_color || '#ffffff');
            root.style.setProperty('--tg-theme-text-color', tg.themeParams.text_color || '#000000');
            root.style.setProperty('--tg-theme-hint-color', tg.themeParams.hint_color || '#8e8e93');
            root.style.setProperty('--tg-theme-link-color', tg.themeParams.link_color || '#007aff');
            root.style.setProperty('--tg-theme-button-color', tg.themeParams.button_color || '#007aff');
            root.style.setProperty('--tg-theme-button-text-color', tg.themeParams.button_text_color || '#ffffff');
            root.style.setProperty('--tg-theme-secondary-bg-color', tg.themeParams.secondary_bg_color || '#f0f2f5');
        }
    }
    
    tg.onEvent('themeChanged', applyTelegramTheme);
    applyTelegramTheme(); // Initial application

    // Start the app
    if (tg.initData) { // Make sure Telegram context is available
      loadInitialData();
    } else {
        // Fallback for local testing if not in Telegram
        console.warn("Telegram WebApp context not found. Running in test mode.");
        // You might want to mock tg.initDataUnsafe.user for local testing
        // For example: tg.initData = "user=" + JSON.stringify({id: 123, first_name: "Test", username: "testuser"});
        // Then call loadInitialData();
        // For now, show an error or a message
        loader.innerHTML = "<p>Please open this app inside Telegram.</p>";
    }
});
