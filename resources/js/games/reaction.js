export function reactionGame() {
    return {
        state: 'idle', // 'idle' | 'waiting' | 'ready' | 'result' | 'early'
        startTime: 0,
        reactionTime: 0,
        timeoutId: null,
        bestTime: parseInt(localStorage.getItem('hpanel_game_reaction_best') || '0', 10),

        start() {
            this.state = 'waiting';
            this.reactionTime = 0;
            clearTimeout(this.timeoutId);

            // Random delay between 1.5s and 4.0s
            const delay = Math.floor(Math.random() * 2500) + 1500;
            this.timeoutId = setTimeout(() => {
                this.state = 'ready';
                this.startTime = Date.now();
            }, delay);
        },

        handleClick() {
            if (this.state === 'idle') {
                this.start();
            } else if (this.state === 'waiting') {
                // Clicked too soon
                clearTimeout(this.timeoutId);
                this.state = 'early';
            } else if (this.state === 'ready') {
                // Success
                this.reactionTime = Date.now() - this.startTime;
                this.state = 'result';

                if (!this.bestTime || this.reactionTime < this.bestTime) {
                    this.bestTime = this.reactionTime;
                    localStorage.setItem('hpanel_game_reaction_best', String(this.bestTime));
                }
            } else {
                this.start();
            }
        },

        reset() {
            clearTimeout(this.timeoutId);
            this.state = 'idle';
            this.reactionTime = 0;
        }
    };
}
