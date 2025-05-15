const tg = window.Telegram.WebApp;

document.addEventListener('DOMContentLoaded', () => {
    tg.ready(); // Inform Telegram that the app is ready
    tg.expand(); // Expand the Web App to full height

    const API_URL = 'api.php';

    // UI Elements
    const appLoader = document.getElementById('app-loader');
    const appContainer = document.getElementById('app-container');
    const pointsDisplay = document.getElementById('points-display');
    const usernameDisplay = document.getElementById('username-display');
    const energyFill = document.getElementById('energy-fill');
    const energyText = document.getElementById('energy-text');

    const mainContent = document.getElementById('main-content');
    const navButtons = document.querySelectorAll('#bottom-nav button');
    const pages = document.querySelectorAll('.page');

    // Profile Page Elements
    const profileName = document.getElementById('profile-name');
    const profileUserId = document.getElementById('profile-user-id');
    const profileJoinDate = document.getElementById('profile-join-date');
    const profileTotalPoints = document.getElementById('profile-total-points');
    const profileTotalReferrals = document.getElementById('profile-total-referrals');
    const profileReferralLink = document.getElementById('profile-referral-link');
    const copyReferralLinkBtn = document.getElementById('copy-referral-link');

    // Tap Page Elements
    const tapImageContainer = document.getElementById('tap-image-container');
    const tapMessage = document.getElementById('tap-message');
    const clicksTodayCount = document.getElementById('clicks-today-count');
    const maxClicksDay = document.getElementById('max-clicks-day');


    // Task Page Elements
    const taskListContainer = document.getElementById('task-list');

    // Ads Page Elements
    const watchAdButton = document.getElementById('watch-ad-button');
    const adRewardPoints = document.getElementById('ad-reward-points');
    const adRewardPointsBtn = document.querySelectorAll('.ad-reward-points-btn');
    const adsRemainingToday = document.getElementById('ads-remaining-today');
    const adCooldownMessage = document.getElementById('ad-cooldown-message');
    const adCooldownTimer = document.getElementById('ad-cooldown-timer');
    const adFeedback = document.getElementById('ad-feedback');

    // Withdraw Page Elements
    const withdrawCurrentPoints = document.getElementById('withdraw-current-points');
    const withdrawForm = document.getElementById('withdraw-form');
    const withdrawMethodSelect = document.getElementById('withdraw-method');
    const upiDetailsDiv = document.getElementById('upi-details');
    const binanceDetailsDiv = document.getElementById('binance-details');
    const withdrawFeedback = document.getElementById('withdraw-feedback');
    const withdrawalHistoryList = document.getElementById('withdrawal-history-list');


    let userData = null;
    let energyInterval = null;
    let adCooldownInterval = null;

    // --- Helper Functions ---
    async function fetchData(action, params = {}) {
        try {
            const queryParams = new URLSearchParams({ action, ...params, tg_user_data: JSON.stringify(tg.initDataUnsafe.user) });
            const response = await fetch(`${API_URL}?${queryParams}`);
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: 'Server error occurred' }));
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }
            return await response.json();
        } catch (error) {
            console.error('Fetch error:', error);
            showFeedback(`Error: ${error.message}`, 'error', 'general'); // You might need a general feedback area
            tg.HapticFeedback.notificationOccurred('error');
            return { success: false, message: error.message };
        }
    }

    function showFeedback(message, type = 'success', page = 'tap') {
        let feedbackEl;
        switch(page) {
            case 'tap': feedbackEl = tapMessage; break;
            case 'ads': feedbackEl = adFeedback; break;
            case 'withdraw': feedbackEl = withdrawFeedback; break;
            default: console.warn("Unknown page for feedback:", page); return;
        }

        if (feedbackEl) {
            feedbackEl.textContent = message;
            feedbackEl.className = `feedback ${type}`;
            setTimeout(() => { if(feedbackEl) feedbackEl.textContent = ''; }, 3000);
        }
    }

    function updateUI() {
        if (!userData) return;

        pointsDisplay.textContent = userData.points || 0;
        usernameDisplay.textContent = userData.first_name || tg.initDataUnsafe.user?.first_name || 'User';
        
        const energyPercent = (userData.energy / userData.max_energy) * 100;
        energyFill.style.width = `${energyPercent}%`;
        energyText.textContent = `⚡${Math.floor(userData.energy)}/${userData.max_energy}`;

        // Profile Page
        profileName.textContent = `${userData.first_name || ''} ${tg.initDataUnsafe.user?.last_name || ''}`.trim() || 'N/A';
        profileUserId.textContent = userData.telegram_user_id || 'N/A';
        profileJoinDate.textContent = userData.join_date ? new Date(userData.join_date).toLocaleDateString() : 'N/A';
        profileTotalPoints.textContent = userData.points || 0;
        profileTotalReferrals.textContent = userData.total_referrals || 0;
        profileReferralLink.value = `https://t.me/${userData.bot_username}?start=${userData.referral_code}`;

        // Tap Page
        clicksTodayCount.textContent = userData.clicks_today || 0;
        maxClicksDay.textContent = userData.max_clicks_per_day || 2500;


        // Ads Page
        adRewardPoints.textContent = userData.points_per_ad || 40;
        adRewardPointsBtn.forEach(el => el.textContent = userData.points_per_ad || 40);
        adsRemainingToday.textContent = (userData.max_ads_per_day || 45) - (userData.ads_watched_today || 0);
        updateAdButtonStatus();


        // Withdraw Page
        withdrawCurrentPoints.textContent = userData.points || 0;
    }

    // --- Navigation ---
    function switchPage(pageId) {
        pages.forEach(page => page.classList.remove('active-page'));
        document.getElementById(pageId).classList.add('active-page');

        navButtons.forEach(button => button.classList.remove('active'));
        document.querySelector(`#bottom-nav button[data-page="${pageId}"]`).classList.add('active');

        // Load data for specific pages when they become active
        if (pageId === 'task-page') loadTasks();
        if (pageId === 'withdraw-page') loadWithdrawalHistory();
    }

    navButtons.forEach(button => {
        button.addEventListener('click', () => {
            switchPage(button.dataset.page);
            tg.HapticFeedback.impactOccurred('light');
        });
    });

    // --- Initialization ---
    async function initializeApp() {
        if (!tg.initDataUnsafe.user || !tg.initDataUnsafe.user.id) {
            appLoader.textContent = "Could not get Telegram user data. Please open this app through Telegram.";
            console.error("Telegram user data is missing.");
            tg.showAlert("Could not get Telegram user data. Please open this app through Telegram.");
            return;
        }

        const referrerId = new URLSearchParams(window.location.hash.substring(1)).get('start'); // For tg.StartParam links
        const params = {};
        if (referrerId) {
            params.referrer_id = referrerId;
        }
        
        const response = await fetchData('init_user', params);
        if (response.success && response.data) {
            userData = response.data;
            updateUI();
            startEnergyRefill();
            appLoader.style.display = 'none';
            appContainer.style.display = 'flex';
            switchPage('tap-page'); // Default page
        } else {
            appLoader.textContent = `Error: ${response.message || 'Failed to initialize app.'}`;
            tg.showAlert(response.message || 'Failed to initialize app.');
        }
    }

    // --- Energy Management ---
    function startEnergyRefill() {
        if (energyInterval) clearInterval(energyInterval);
        energyInterval = setInterval(async () => {
            if (userData && userData.energy < userData.max_energy) {
                // Client-side optimistic update for smoothness
                const energyGain = userData.energy_refill_rate_per_second; // gain per second
                userData.energy = Math.min(userData.max_energy, userData.energy + energyGain);
                updateUI();

                // Sync with server periodically or rely on server to correct on next action
                // For now, server will calculate on tap/action
            }
        }, 1000); // Update energy every second
    }
    
    // --- Profile Section ---
    copyReferralLinkBtn.addEventListener('click', () => {
        profileReferralLink.select();
        document.execCommand('copy');
        tg.HapticFeedback.notificationOccurred('success');
        tg.showAlert('Referral link copied!');
    });

    // --- Tap Section Logic ---
    tapImageContainer.addEventListener('click', async () => {
        if (!userData) return;

        if (userData.energy < 1) {
            showFeedback('Not enough energy! Wait for it to refill.', 'error', 'tap');
            tg.HapticFeedback.notificationOccurred('error');
            return;
        }
        if (userData.clicks_today >= userData.max_clicks_per_day) {
            showFeedback('Daily tap limit reached!', 'error', 'tap');
            tg.HapticFeedback.notificationOccurred('error');
            return;
        }

        tg.HapticFeedback.impactOccurred('medium');
        // Optimistic UI update
        userData.points += userData.points_per_tap;
        userData.energy -= 1;
        userData.clicks_today += 1;
        updateUI();
        showFeedback(`+${userData.points_per_tap} Point!`, 'success', 'tap');

        const response = await fetchData('tap');
        if (response.success && response.data) {
            userData = response.data; // Update with authoritative data
            updateUI();
        } else {
            // Revert optimistic update if server fails
            userData.points -= userData.points_per_tap;
            userData.energy += 1;
            userData.clicks_today -= 1;
            updateUI();
            showFeedback(response.message || 'Tap error', 'error', 'tap');
            tg.HapticFeedback.notificationOccurred('error');
        }
    });

    // --- Task Section Logic ---
    async function loadTasks() {
        taskListContainer.innerHTML = '<p>Loading tasks...</p>';
        const response = await fetchData('get_tasks');
        if (response.success && response.data) {
            renderTasks(response.data.tasks, response.data.completed_today);
        } else {
            taskListContainer.innerHTML = `<p class="error">${response.message || 'Failed to load tasks.'}</p>`;
        }
    }

    function renderTasks(tasks, completedTodayIds) {
        if (!tasks || tasks.length === 0) {
            taskListContainer.innerHTML = '<p>No tasks available at the moment.</p>';
            return;
        }
        taskListContainer.innerHTML = '';
        tasks.forEach(task => {
            const isCompleted = completedTodayIds && completedTodayIds.includes(task.id);
            const taskItem = document.createElement('div');
            taskItem.className = 'task-item';
            taskItem.innerHTML = `
                <h3>${task.title}</h3>
                <p>${task.description || ''}</p>
                <p class="task-reward">Reward: ${task.points_reward} Points</p>
                ${isCompleted ? '<p class="task-completed">Completed Today!</p>' : 
                    `<button data-task-id="${task.id}" data-task-link="${task.link}">Go to Task</button>`}
            `;
            taskListContainer.appendChild(taskItem);

            if (!isCompleted) {
                taskItem.querySelector('button').addEventListener('click', () => {
                    handleTaskClick(task.id, task.link);
                });
            }
        });
    }

    function handleTaskClick(taskId, taskLink) {
        // Open link in Telegram browser or external if specified by TWA
        tg.openLink(taskLink); 
        // User needs to manually come back and claim or we assume completion after a delay
        // For simplicity, we'll provide a button to "Verify Task" or automatically verify on next load
        // Here, we'll add a "Claim" button after a short delay or a way to mark as "checked"
        tg.showAlert(`You are being redirected to complete the task. After completion, revisit the Tasks page. Task status will refresh.`);
        // After user returns, they can click a "Verify" button or it auto-verifies
        // For now, we'll mark it as potentially done and let server verify (or rely on user honesty)
        // A better approach would be bot integration to verify channel joins.
        // For this example, let's simulate verification after a delay or on next task load.
        // We can add a "Mark as Checked" button that then calls the API.

        // Let's add a "Claim Reward" button that appears or becomes active.
        // For this version, task completion is handled server-side when 'complete_task' is called.
        // The simplest flow for now: user clicks, goes to link, comes back.
        // Then they click another button "I've completed this" or it refreshes.

        // For now, let's assume they complete it and we need a way to confirm.
        // We can add a "Claim Reward" button that appears after they click "Go to Task".
        // Or refresh task list on page re-focus.

        const claimButton = taskListContainer.querySelector(`button[data-task-id="${taskId}"]`);
        if (claimButton) {
            claimButton.textContent = 'Claim Reward';
            claimButton.onclick = async () => {
                claimButton.disabled = true;
                claimButton.textContent = 'Claiming...';
                const response = await fetchData('complete_task', { task_id: taskId });
                if (response.success) {
                    userData = response.data.user_data; // Update user data
                    updateUI();
                    loadTasks(); // Refresh task list
                    tg.HapticFeedback.notificationOccurred('success');
                    tg.showAlert(`Task completed! +${response.data.reward} points.`);
                } else {
                    tg.HapticFeedback.notificationOccurred('error');
                    tg.showAlert(response.message || 'Failed to claim task reward.');
                    claimButton.disabled = false;
                    claimButton.textContent = 'Claim Reward';
                }
            };
        }
    }
    

    // --- Ads Section Logic ---
    function updateAdButtonStatus() {
        if (!userData) return;

        const adsWatched = userData.ads_watched_today || 0;
        const maxAds = userData.max_ads_per_day || 45;
        const adsLeft = maxAds - adsWatched;
        adsRemainingToday.textContent = adsLeft;

        if (adsLeft <= 0) {
            watchAdButton.disabled = true;
            watchAdButton.textContent = 'Daily Ad Limit Reached';
            adCooldownMessage.style.display = 'none';
            return;
        }

        if (userData.next_ad_available_at) {
            const now = new Date().getTime();
            const nextAdTime = new Date(userData.next_ad_available_at).getTime();
            if (now < nextAdTime) {
                watchAdButton.disabled = true;
                adCooldownMessage.style.display = 'block';
                startAdCooldownTimer(nextAdTime - now);
                return;
            }
        }
        
        watchAdButton.disabled = false;
        watchAdButton.innerHTML = `Watch Ad (Earn <span class="ad-reward-points-btn">${userData.points_per_ad || 40}</span> Points)`;
        adCooldownMessage.style.display = 'none';
        if (adCooldownInterval) clearInterval(adCooldownInterval);
    }

    function startAdCooldownTimer(durationMs) {
        if (adCooldownInterval) clearInterval(adCooldownInterval);
        let timeLeft = Math.ceil(durationMs / 1000);
        
        function updateTimerDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            adCooldownTimer.textContent = `${minutes}m ${seconds < 10 ? '0' : ''}${seconds}s`;
        }

        updateTimerDisplay(); // Initial display
        adCooldownInterval = setInterval(() => {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(adCooldownInterval);
                adCooldownMessage.style.display = 'none';
                watchAdButton.disabled = false;
                 watchAdButton.innerHTML = `Watch Ad (Earn <span class="ad-reward-points-btn">${userData.points_per_ad || 40}</span> Points)`;
            } else {
                updateTimerDisplay();
            }
        }, 1000);
    }

    watchAdButton.addEventListener('click', () => {
        if (typeof show_9321934 !== 'function') {
            tg.showAlert('Ad SDK not loaded. Please try again later.');
            console.error('Monetag SDK function show_9321934 not found.');
            return;
        }

        watchAdButton.disabled = true;
        watchAdButton.textContent = 'Loading Ad...';

        show_9321934() // Using Rewarded Interstitial as per user preference
            .then(async () => {
                // Ad watched successfully
                tg.HapticFeedback.notificationOccurred('success');
                showFeedback('Ad watched! Claiming reward...', 'success', 'ads');
                
                const response = await fetchData('watched_ad');
                if (response.success && response.data) {
                    userData = response.data;
                    updateUI();
                    updateAdButtonStatus();
                    showFeedback(`+${userData.points_per_ad} points for watching the ad!`, 'success', 'ads');
                } else {
                    showFeedback(response.message || 'Error claiming ad reward.', 'error', 'ads');
                    tg.HapticFeedback.notificationOccurred('error');
                }
            })
            .catch(e => {
                // Ad failed to show or was closed early (for non-rewarded interstitial, this might still be a success)
                // For rewarded, an error here means no reward.
                console.error('Ad error:', e);
                showFeedback('Ad could not be shown or was closed too early. No reward.', 'error', 'ads');
                tg.HapticFeedback.notificationOccurred('error');
                // Re-enable button if it's not a cooldown issue but a show error
                // Server will manage cooldowns, so UI should reflect server state.
                updateAdButtonStatus(); 
            });
    });

    // --- Withdraw Section Logic ---
    withdrawMethodSelect.addEventListener('change', (e) => {
        upiDetailsDiv.style.display = e.target.value === 'UPI' ? 'block' : 'none';
        binanceDetailsDiv.style.display = e.target.value === 'Binance' ? 'block' : 'none';
    });

    withdrawForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitButton = withdrawForm.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.textContent = 'Processing...';

        const formData = new FormData(withdrawForm);
        const method = formData.get('method');
        const amount = parseInt(formData.get('amount'));
        let details = {};

        if (method === 'UPI') {
            details.upi_id = formData.get('upi_id');
            if (!details.upi_id) {
                showFeedback('UPI ID is required.', 'error', 'withdraw');
                submitButton.disabled = false;
                submitButton.textContent = 'Request Withdrawal';
                return;
            }
        } else if (method === 'Binance') {
            details.binance_address = formData.get('binance_address');
            details.binance_memo = formData.get('binance_memo'); // Optional
            if (!details.binance_address) {
                showFeedback('Binance Address is required.', 'error', 'withdraw');
                submitButton.disabled = false;
                submitButton.textContent = 'Request Withdrawal';
                return;
            }
        }

        if (userData.points < amount) {
            showFeedback('Not enough points for this withdrawal amount.', 'error', 'withdraw');
            submitButton.disabled = false;
            submitButton.textContent = 'Request Withdrawal';
            return;
        }

        const response = await fetchData('request_withdrawal', {
            amount: amount,
            method: method,
            details: JSON.stringify(details)
        });

        if (response.success) {
            userData = response.data.user_data; // Update user data (points)
            updateUI();
            showFeedback('Withdrawal request submitted successfully!', 'success', 'withdraw');
            tg.HapticFeedback.notificationOccurred('success');
            withdrawForm.reset(); // Reset form
            upiDetailsDiv.style.display = 'block'; // Reset to default view
            binanceDetailsDiv.style.display = 'none';
            loadWithdrawalHistory(); // Refresh history
        } else {
            showFeedback(response.message || 'Withdrawal request failed.', 'error', 'withdraw');
            tg.HapticFeedback.notificationOccurred('error');
        }
        submitButton.disabled = false;
        submitButton.textContent = 'Request Withdrawal';
    });

    async function loadWithdrawalHistory() {
        withdrawalHistoryList.innerHTML = '<p>Loading history...</p>';
        const response = await fetchData('get_withdrawal_history');
        if (response.success && response.data) {
            renderWithdrawalHistory(response.data);
        } else {
            withdrawalHistoryList.innerHTML = `<p class="error">${response.message || 'Failed to load withdrawal history.'}</p>`;
        }
    }

    function renderWithdrawalHistory(history) {
        if (!history || history.length === 0) {
            withdrawalHistoryList.innerHTML = '<p>No withdrawal history found.</p>';
            return;
        }
        withdrawalHistoryList.innerHTML = '';
        history.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'history-item';
            itemDiv.innerHTML = `
                <span><strong>Amount:</strong> ${item.points_withdrawn} points</span>
                <span><strong>Method:</strong> ${item.method}</span>
                <span><strong>Status:</strong> <span class="status-${item.status.toLowerCase()}">${item.status}</span></span>
                <span><strong>Date:</strong> ${new Date(item.requested_at).toLocaleString()}</span>
            `;
            withdrawalHistoryList.appendChild(itemDiv);
        });
    }
    
    // Initialize the app
    initializeApp();
});
