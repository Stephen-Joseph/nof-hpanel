export function typingGame() {
    const WORDS = [
        'nginx', 'php-fpm', 'certbot', 'mariadb', 'systemd', 'wireguard',
        'redis', 'docker', 'cpanel', 'restic', 'cron', 'mysql', 'laravel',
        'sqlite', 'fastcgi', 'ubuntu', 'debian', 'chroot', 'sftp', 'dns',
        'nameserver', 'domain', 'socket', 'daemon', 'firewall', 'openssh'
    ];

    return {
        wordList: [],
        currentWordIndex: 0,
        userInput: '',
        startTime: null,
        totalTyped: 0,
        correctTyped: 0,
        wpm: 0,
        accuracy: 100,
        highWpm: parseInt(localStorage.getItem('hpanel_game_typing_best') || '0', 10),
        isPlaying: false,
        isFinished: false,
        timerInterval: null,
        durationSeconds: 30,
        timeLeft: 30,

        init() {
            this.reset();
        },

        start() {
            this.reset();
            this.isPlaying = true;
            this.startTime = Date.now();
            this.timeLeft = this.durationSeconds;

            this.$nextTick(() => {
                this.$refs.typingInput?.focus();
            });

            clearInterval(this.timerInterval);
            this.timerInterval = setInterval(() => {
                this.timeLeft--;
                this.calculateStats();
                if (this.timeLeft <= 0) {
                    this.finish();
                }
            }, 1000);
        },

        reset() {
            clearInterval(this.timerInterval);
            this.isPlaying = false;
            this.isFinished = false;
            this.userInput = '';
            this.currentWordIndex = 0;
            this.totalTyped = 0;
            this.correctTyped = 0;
            this.wpm = 0;
            this.accuracy = 100;
            this.timeLeft = this.durationSeconds;

            // Pick 20 randomized words
            this.wordList = [...WORDS].sort(() => 0.5 - Math.random()).slice(0, 20);
        },

        handleInput(e) {
            if (!this.isPlaying || this.isFinished) return;

            const val = this.userInput;
            const currentWord = this.wordList[this.currentWordIndex];

            // When user hits space or completes word
            if (val.endsWith(' ') || val === currentWord) {
                const trimmed = val.trim();
                if (trimmed.length > 0) {
                    this.totalTyped += trimmed.length;
                    if (trimmed === currentWord) {
                        this.correctTyped += trimmed.length;
                    }

                    this.currentWordIndex++;
                    this.userInput = '';

                    if (this.currentWordIndex >= this.wordList.length) {
                        this.finish();
                        return;
                    }
                }
            }

            this.calculateStats();
        },

        calculateStats() {
            if (!this.startTime) return;
            const elapsedMins = (Date.now() - this.startTime) / 60000;
            if (elapsedMins > 0) {
                // Standard 5 chars = 1 word
                this.wpm = Math.round((this.correctTyped / 5) / elapsedMins);
            }
            if (this.totalTyped > 0) {
                this.accuracy = Math.round((this.correctTyped / this.totalTyped) * 100);
            }
        },

        finish() {
            clearInterval(this.timerInterval);
            this.isFinished = true;
            this.isPlaying = false;
            this.calculateStats();

            if (this.wpm > this.highWpm) {
                this.highWpm = this.wpm;
                localStorage.setItem('hpanel_game_typing_best', String(this.wpm));
            }
        }
    };
}
