export function snakeGame() {
    return {
        canvas: null,
        ctx: null,
        gridSize: 15,
        tileCount: 18,
        snake: [],
        food: { x: 5, y: 5 },
        dx: 1,
        dy: 0,
        nextDx: 1,
        nextDy: 0,
        score: 0,
        highScore: parseInt(localStorage.getItem('hpanel_game_snake_best') || '0', 10),
        isPlaying: false,
        isPaused: false,
        isGameOver: false,
        loopInterval: null,
        speedMs: 110,

        init() {
            this.$nextTick(() => {
                this.canvas = this.$refs.snakeCanvas;
                if (this.canvas) {
                    this.ctx = this.canvas.getContext('2d');
                    this.drawInitial();
                }
            });

            window.addEventListener('keydown', (e) => {
                if (!this.isPlaying || this.isPaused) return;
                switch (e.key) {
                    case 'ArrowUp':
                    case 'w':
                    case 'W':
                        if (this.dy === 0) { this.nextDx = 0; this.nextDy = -1; e.preventDefault(); }
                        break;
                    case 'ArrowDown':
                    case 's':
                    case 'S':
                        if (this.dy === 0) { this.nextDx = 0; this.nextDy = 1; e.preventDefault(); }
                        break;
                    case 'ArrowLeft':
                    case 'a':
                    case 'A':
                        if (this.dx === 0) { this.nextDx = -1; this.nextDy = 0; e.preventDefault(); }
                        break;
                    case 'ArrowRight':
                    case 'd':
                    case 'D':
                        if (this.dx === 0) { this.nextDx = 1; this.nextDy = 0; e.preventDefault(); }
                        break;
                    case 'Escape':
                        this.togglePause();
                        e.preventDefault();
                        break;
                }
            });

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
            this.snake = [
                { x: 8, y: 9 },
                { x: 7, y: 9 },
                { x: 6, y: 9 }
            ];
            this.dx = 1;
            this.dy = 0;
            this.nextDx = 1;
            this.nextDy = 0;
            this.score = 0;
            this.isGameOver = false;
            this.isPaused = false;
            this.isPlaying = true;
            this.spawnFood();

            clearInterval(this.loopInterval);
            this.loopInterval = setInterval(() => this.tick(), this.speedMs);
        },

        pause() {
            if (!this.isPlaying) return;
            this.isPaused = true;
            clearInterval(this.loopInterval);
        },

        resume() {
            this.isPaused = false;
            clearInterval(this.loopInterval);
            this.loopInterval = setInterval(() => this.tick(), this.speedMs);
        },

        togglePause() {
            if (this.isPaused) this.resume();
            else this.pause();
        },

        tick() {
            this.dx = this.nextDx;
            this.dy = this.nextDy;

            const head = { x: this.snake[0].x + this.dx, y: this.snake[0].y + this.dy };

            // Wall collisions (wrap-around for enjoyable casual play)
            if (head.x < 0) head.x = this.tileCount - 1;
            if (head.x >= this.tileCount) head.x = 0;
            if (head.y < 0) head.y = this.tileCount - 1;
            if (head.y >= this.tileCount) head.y = 0;

            // Self collision
            for (let i = 1; i < this.snake.length; i++) {
                if (head.x === this.snake[i].x && head.y === this.snake[i].y) {
                    this.gameOver();
                    return;
                }
            }

            this.snake.unshift(head);

            // Eat food
            if (head.x === this.food.x && head.y === this.food.y) {
                this.score += 10;
                if (this.score > this.highScore) {
                    this.highScore = this.score;
                    localStorage.setItem('hpanel_game_snake_best', String(this.score));
                }
                this.spawnFood();
            } else {
                this.snake.pop();
            }

            this.draw();
        },

        spawnFood() {
            let valid = false;
            while (!valid) {
                this.food.x = Math.floor(Math.random() * this.tileCount);
                this.food.y = Math.floor(Math.random() * this.tileCount);
                valid = !this.snake.some(segment => segment.x === this.food.x && segment.y === this.food.y);
            }
        },

        draw() {
            if (!this.ctx || !this.canvas) return;
            const isDark = document.documentElement.classList.contains('dark');

            // Clear canvas
            this.ctx.fillStyle = isDark ? '#090d16' : '#f8fafc';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

            // Draw food (pulsing blue or emerald)
            this.ctx.fillStyle = '#38bdf8';
            this.ctx.beginPath();
            const radius = this.gridSize / 2.2;
            const fx = this.food.x * this.gridSize + this.gridSize / 2;
            const fy = this.food.y * this.gridSize + this.gridSize / 2;
            this.ctx.arc(fx, fy, radius, 0, Math.PI * 2);
            this.ctx.fill();

            // Draw snake
            this.snake.forEach((seg, i) => {
                this.ctx.fillStyle = i === 0 ? '#2563eb' : (isDark ? '#60a5fa' : '#3b82f6');
                const pad = 1;
                this.ctx.beginPath();
                this.ctx.roundRect(
                    seg.x * this.gridSize + pad,
                    seg.y * this.gridSize + pad,
                    this.gridSize - pad * 2,
                    this.gridSize - pad * 2,
                    i === 0 ? 4 : 2
                );
                this.ctx.fill();
            });
        },

        drawInitial() {
            if (!this.ctx || !this.canvas) return;
            const isDark = document.documentElement.classList.contains('dark');
            this.ctx.fillStyle = isDark ? '#090d16' : '#f8fafc';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
        },

        gameOver() {
            this.isGameOver = true;
            this.isPlaying = false;
            clearInterval(this.loopInterval);
        },

        setDir(dx, dy) {
            if (!this.isPlaying || this.isPaused) return;
            if (dx !== 0 && this.dx === 0) { this.nextDx = dx; this.nextDy = 0; }
            if (dy !== 0 && this.dy === 0) { this.nextDx = 0; this.nextDy = dy; }
        }
    };
}

