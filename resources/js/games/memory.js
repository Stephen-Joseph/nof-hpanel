export function memoryGame() {
    const ICONS = ['🖥️', '🌐', '🗄️', '🛡️', '☁️', '🔒', '⚡', '🚀'];

    return {
        cards: [],
        flippedCards: [],
        matchedPairs: 0,
        moves: 0,
        timer: 0,
        timerInterval: null,
        isPlaying: false,
        isPaused: false,
        isWon: false,
        highScore: parseInt(localStorage.getItem('hpanel_game_memory_best') || '0', 10),

        init() {
            this.reset();
            document.addEventListener('visibilitychange', () => {
                if (document.hidden && this.isPlaying && !this.isPaused) {
                    this.pause();
                }
            });
        },

        start() {
            if (this.isPaused) {
                this.resume();
                return;
            }
            this.reset();
            this.isPlaying = true;
            this.isPaused = false;
            this.startTimer();
        },

        pause() {
            if (!this.isPlaying) return;
            this.isPaused = true;
            clearInterval(this.timerInterval);
        },

        resume() {
            this.isPaused = false;
            this.startTimer();
        },

        togglePlay() {
            if (!this.isPlaying) this.start();
            else if (this.isPaused) this.resume();
            else this.pause();
        },

        startTimer() {
            clearInterval(this.timerInterval);
            this.timerInterval = setInterval(() => {
                this.timer++;
            }, 1000);
        },

        reset() {
            clearInterval(this.timerInterval);
            this.timer = 0;
            this.moves = 0;
            this.matchedPairs = 0;
            this.flippedCards = [];
            this.isPlaying = false;
            this.isPaused = false;
            this.isWon = false;

            // Generate 8 pairs (16 cards) and shuffle
            const deck = [...ICONS, ...ICONS];
            for (let i = deck.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [deck[i], deck[j]] = [deck[j], deck[i]];
            }

            this.cards = deck.map((icon, id) => ({
                id,
                icon,
                flipped: false,
                matched: false
            }));
        },

        flip(card) {
            if (!this.isPlaying || this.isPaused || card.flipped || card.matched || this.flippedCards.length >= 2) {
                return;
            }

            card.flipped = true;
            this.flippedCards.push(card);

            if (this.flippedCards.length === 2) {
                this.moves++;
                const [c1, c2] = this.flippedCards;
                if (c1.icon === c2.icon) {
                    c1.matched = true;
                    c2.matched = true;
                    this.matchedPairs++;
                    this.flippedCards = [];

                    if (this.matchedPairs === ICONS.length) {
                        this.win();
                    }
                } else {
                    setTimeout(() => {
                        c1.flipped = false;
                        c2.flipped = false;
                        this.flippedCards = [];
                    }, 800);
                }
            }
        },

        win() {
            this.isWon = true;
            this.isPlaying = false;
            clearInterval(this.timerInterval);

            // Score formula: fewer moves + faster time = better score
            const score = Math.max(10, 1000 - (this.moves * 25) - (this.timer * 10));
            if (!this.highScore || score > this.highScore) {
                this.highScore = score;
                localStorage.setItem('hpanel_game_memory_best', String(score));
            }
        },

        formatTime(sec) {
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
        }
    };
}
