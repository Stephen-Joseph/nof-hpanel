export function gameHost() {
    return {
        enabled: localStorage.getItem('hpanel_play_while_wait') === 'true',
        activeGame: 'memory',
        jobCompleted: false,

        init() {
            window.addEventListener('job-completed', () => {
                this.jobCompleted = true;
            });
        },

        toggleEnabled() {
            this.enabled = !this.enabled;
            localStorage.setItem('hpanel_play_while_wait', String(this.enabled));
        },

        setGame(game) {
            this.activeGame = game;
        }
    };
}

