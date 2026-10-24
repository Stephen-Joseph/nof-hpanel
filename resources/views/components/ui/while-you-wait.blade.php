@props([
    'jobId' => null,
    'isRunning' => true,
])

<div
    x-data="gameHost()"
    class="w-full mt-4 select-none"
    x-cloak
>
    <!-- Toggle Header Bar -->
    <div class="flex items-center justify-between px-2 py-1 mb-2">
        <div class="flex items-center gap-2">
            <span class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider flex items-center gap-1.5">
                <span>🎮</span> While You Wait
            </span>
            <span class="text-[11px] text-[var(--text-muted)] hidden sm:inline">&middot; Optional mini-games</span>
        </div>

        <button
            type="button"
            @click="toggleEnabled()"
            class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium border transition-all cursor-pointer"
            :class="enabled 
                ? 'bg-blue-600 text-white border-blue-600 shadow-2xs hover:bg-blue-700' 
                : 'bg-[var(--bg-card)] text-[var(--text-muted)] border-[var(--border)] hover:text-[var(--text)] hover:border-slate-400'"
        >
            <span class="w-2 h-2 rounded-full" :class="enabled ? 'bg-white' : 'bg-slate-400'"></span>
            <span x-text="enabled ? 'Hide Games' : 'Play while you wait'"></span>
        </button>
    </div>

    <!-- Collapsible Game Container (Compact, Max ~320px) -->
    <div
        x-show="enabled"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-2"
        class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[24px] p-4 sm:p-5 shadow-xs overflow-hidden max-h-[380px] flex flex-col justify-between"
    >
        <!-- Celebration Banner when Job Completes -->
        <div x-show="jobCompleted" class="mb-3 p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-center">
            <p class="text-xs font-bold text-emerald-600 dark:text-emerald-400">
                🎉 Server task completed successfully! Ready to continue.
            </p>
        </div>

        <!-- Top Controls: Game Switcher Tabs -->
        <div class="flex items-center justify-between gap-2 border-b border-[var(--border)] pb-3 mb-3">
            <div class="flex items-center gap-1 overflow-x-auto thin-scroll">
                <button
                    type="button"
                    @click="setGame('memory')"
                    class="px-3 py-1 rounded-full text-xs font-semibold transition cursor-pointer"
                    :class="activeGame === 'memory' ? 'bg-blue-600 text-white shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                >
                    🃏 Memory
                </button>
                <button
                    type="button"
                    @click="setGame('snake')"
                    class="px-3 py-1 rounded-full text-xs font-semibold transition cursor-pointer"
                    :class="activeGame === 'snake' ? 'bg-blue-600 text-white shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                >
                    🐍 Snake
                </button>
                <button
                    type="button"
                    @click="setGame('typing')"
                    class="px-3 py-1 rounded-full text-xs font-semibold transition cursor-pointer"
                    :class="activeGame === 'typing' ? 'bg-blue-600 text-white shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                >
                    ⌨️ Typing
                </button>
                <button
                    type="button"
                    @click="setGame('reaction')"
                    class="px-3 py-1 rounded-full text-xs font-semibold transition cursor-pointer"
                    :class="activeGame === 'reaction' ? 'bg-blue-600 text-white shadow-2xs' : 'text-[var(--text-muted)] hover:text-[var(--text)]'"
                >
                    ⚡ Reaction
                </button>
            </div>
        </div>

        <!-- GAME 1: MEMORY MATCH -->
        <div x-show="activeGame === 'memory'" x-data="memoryGame()" class="flex-1 flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--text-muted)] mb-2 px-1">
                <div class="flex items-center gap-3">
                    <span>Moves: <strong class="text-[var(--text)]" x-text="moves"></strong></span>
                    <span>Time: <strong class="text-[var(--text)]" x-text="formatTime(timer)"></strong></span>
                </div>
                <div>
                    <span>Best Score: <strong class="text-blue-500" x-text="highScore"></strong></span>
                </div>
            </div>

            <!-- 4x4 Grid (Compact) -->
            <div class="grid grid-cols-4 gap-1.5 sm:gap-2 max-w-[280px] mx-auto w-full my-auto">
                <template x-for="card in cards" :key="card.id">
                    <button
                        type="button"
                        @click="flip(card)"
                        class="h-11 sm:h-12 rounded-xl flex items-center justify-center text-lg sm:text-xl font-bold transition-all duration-200 cursor-pointer shadow-2xs"
                        :class="card.flipped || card.matched 
                            ? 'bg-blue-50 dark:bg-blue-500/20 border-2 border-blue-500' 
                            : 'bg-slate-100 dark:bg-slate-800 border border-[var(--border)] hover:bg-slate-200 dark:hover:bg-slate-700'"
                    >
                        <span x-show="card.flipped || card.matched" x-text="card.icon"></span>
                        <span x-show="!card.flipped && !card.matched" class="text-slate-400 text-xs font-mono font-bold">?</span>
                    </button>
                </template>
            </div>

            <!-- Memory Game Controls -->
            <div class="flex items-center justify-center gap-2 mt-3 pt-2 border-t border-[var(--border)]">
                <button
                    type="button"
                    @click="togglePlay()"
                    class="px-4 py-1 rounded-full text-xs font-semibold bg-blue-600 hover:bg-blue-700 text-white shadow-2xs transition cursor-pointer"
                    x-text="!isPlaying ? 'Start' : (isPaused ? 'Resume' : 'Pause')"
                ></button>
                <button
                    type="button"
                    @click="reset()"
                    class="px-3 py-1 rounded-full text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text)] border border-[var(--border)] transition cursor-pointer"
                >
                    Restart
                </button>
            </div>
        </div>

        <!-- GAME 2: SNAKE -->
        <div x-show="activeGame === 'snake'" x-data="snakeGame()" class="flex-1 flex flex-col items-center justify-between">
            <div class="w-full flex items-center justify-between text-xs text-[var(--text-muted)] mb-2 px-1">
                <span>Score: <strong class="text-[var(--text)]" x-text="score"></strong></span>
                <span>Best: <strong class="text-blue-500" x-text="highScore"></strong></span>
            </div>

            <!-- Canvas Container -->
            <div class="relative rounded-2xl overflow-hidden border border-[var(--border)] bg-slate-950 shadow-inner">
                <canvas x-ref="snakeCanvas" width="270" height="150" class="block"></canvas>
                
                <div x-show="!isPlaying && !isGameOver" class="absolute inset-0 bg-slate-950/60 flex items-center justify-center backdrop-blur-2xs">
                    <button type="button" @click="start()" class="px-4 py-1.5 rounded-full text-xs font-bold bg-blue-600 text-white shadow-md hover:bg-blue-700 transition cursor-pointer">
                        Play Snake
                    </button>
                </div>

                <div x-show="isGameOver" class="absolute inset-0 bg-slate-950/75 flex flex-col items-center justify-center backdrop-blur-2xs">
                    <p class="text-xs font-bold text-rose-400 mb-1">Game Over!</p>
                    <button type="button" @click="start()" class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-600 text-white shadow-2xs hover:bg-blue-700 transition cursor-pointer">
                        Play Again
                    </button>
                </div>
            </div>

            <!-- Snake D-Pad / Buttons (Mobile Accessible) -->
            <div class="flex items-center justify-between w-full max-w-[270px] mt-2 pt-2 border-t border-[var(--border)]">
                <div class="flex items-center gap-1">
                    <button type="button" @click="togglePause()" class="px-2.5 py-0.5 rounded-full text-xs text-[var(--text-muted)] hover:text-[var(--text)] border border-[var(--border)]">
                        <span x-text="isPaused ? 'Resume' : 'Pause'"></span>
                    </button>
                </div>
                <!-- On-screen directional arrows for mobile -->
                <div class="flex items-center gap-1">
                    <button type="button" @click="setDir(-1, 0)" class="w-6 h-6 rounded-md border border-[var(--border)] bg-[var(--bg-shell)] flex items-center justify-center text-xs font-bold">←</button>
                    <div class="flex flex-col gap-0.5">
                        <button type="button" @click="setDir(0, -1)" class="w-6 h-3 rounded-md border border-[var(--border)] bg-[var(--bg-shell)] flex items-center justify-center text-[10px] font-bold">↑</button>
                        <button type="button" @click="setDir(0, 1)" class="w-6 h-3 rounded-md border border-[var(--border)] bg-[var(--bg-shell)] flex items-center justify-center text-[10px] font-bold">↓</button>
                    </div>
                    <button type="button" @click="setDir(1, 0)" class="w-6 h-6 rounded-md border border-[var(--border)] bg-[var(--bg-shell)] flex items-center justify-center text-xs font-bold">→</button>
                </div>
            </div>
        </div>

        <!-- GAME 3: TYPING TEST -->
        <div x-show="activeGame === 'typing'" x-data="typingGame()" class="flex-1 flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--text-muted)] mb-2 px-1">
                <div class="flex items-center gap-3">
                    <span>WPM: <strong class="text-blue-500 font-bold" x-text="wpm"></strong></span>
                    <span>Acc: <strong class="text-[var(--text)]" x-text="accuracy + '%'"></strong></span>
                </div>
                <div class="flex items-center gap-2">
                    <span>Time: <strong class="text-[var(--text)]" x-text="timeLeft + 's'"></strong></span>
                    <span>Best: <strong class="text-emerald-500" x-text="highWpm"></strong></span>
                </div>
            </div>

            <!-- Words Stream Display -->
            <div class="p-3 rounded-2xl bg-slate-100 dark:bg-slate-900 border border-[var(--border)] min-h-[70px] flex flex-wrap gap-1.5 items-center justify-center overflow-hidden">
                <template x-for="(word, idx) in wordList" :key="idx">
                    <span
                        class="px-2 py-0.5 rounded-lg text-xs font-mono font-medium transition"
                        :class="idx === currentWordIndex 
                            ? 'bg-blue-600 text-white font-bold shadow-2xs' 
                            : (idx < currentWordIndex ? 'text-emerald-500 dark:text-emerald-400 line-through' : 'text-slate-400 dark:text-slate-500')"
                        x-text="word"
                    ></span>
                </template>
            </div>

            <!-- Input Box -->
            <div class="mt-3 flex items-center gap-2">
                <input
                    x-ref="typingInput"
                    type="text"
                    x-model="userInput"
                    @input="handleInput($event)"
                    :disabled="!isPlaying || isFinished"
                    placeholder="Type words here..."
                    class="flex-1 h-9 px-3 rounded-full border border-[var(--border)] bg-[var(--bg-card)] text-xs font-mono focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 disabled:opacity-50"
                />
                <button
                    type="button"
                    @click="start()"
                    class="px-4 py-1.5 rounded-full text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white shadow-2xs transition cursor-pointer"
                    x-text="!isPlaying ? 'Start Test' : 'Restart'"
                ></button>
            </div>
        </div>

        <!-- GAME 4: REACTION TEST -->
        <div x-show="activeGame === 'reaction'" x-data="reactionGame()" class="flex-1 flex flex-col justify-between">
            <div class="flex items-center justify-between text-xs text-[var(--text-muted)] mb-2 px-1">
                <span>Test your reflex speed</span>
                <span>Best: <strong class="text-emerald-500" x-text="bestTime ? bestTime + ' ms' : '--'"></strong></span>
            </div>

            <!-- Big Tap Target -->
            <button
                type="button"
                @click="handleClick()"
                class="w-full h-32 rounded-2xl border flex flex-col items-center justify-center transition-all duration-150 cursor-pointer shadow-xs select-none"
                :class="{
                    'bg-blue-600 hover:bg-blue-700 border-blue-600 text-white': state === 'idle',
                    'bg-amber-500 border-amber-600 text-white': state === 'waiting',
                    'bg-emerald-500 border-emerald-600 text-white scale-[1.02] ring-4 ring-emerald-400/30': state === 'ready',
                    'bg-rose-500 border-rose-600 text-white': state === 'early',
                    'bg-[var(--bg-card)] border-[var(--border)] text-[var(--text)]': state === 'result'
                }"
            >
                <template x-if="state === 'idle'">
                    <span class="text-sm font-bold">Click to Start Reaction Test</span>
                </template>
                <template x-if="state === 'waiting'">
                    <span class="text-sm font-bold animate-pulse">Wait for green...</span>
                </template>
                <template x-if="state === 'ready'">
                    <span class="text-base font-black">CLICK NOW!</span>
                </template>
                <template x-if="state === 'early'">
                    <div class="text-center">
                        <span class="text-sm font-bold block">Too early! ⏱️</span>
                        <span class="text-xs opacity-90">Click to try again</span>
                    </div>
                </template>
                <template x-if="state === 'result'">
                    <div class="text-center">
                        <span class="text-2xl font-black text-blue-500 block" x-text="reactionTime + ' ms'"></span>
                        <span class="text-xs text-[var(--text-muted)]">Click anywhere to retry</span>
                    </div>
                </template>
            </button>

            <div class="flex items-center justify-center mt-2 pt-2 border-t border-[var(--border)]">
                <button
                    type="button"
                    @click="reset()"
                    class="px-3 py-1 rounded-full text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text)] border border-[var(--border)] transition cursor-pointer"
                >
                    Reset
                </button>
            </div>
        </div>
    </div>
</div>
