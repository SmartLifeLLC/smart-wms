/**
 * Picking Execution Screen JavaScript
 * Handles auto-focus and Enter key navigation for picking input fields
 */

document.addEventListener('DOMContentLoaded', function() {
    // 最初の入力欄にフォーカス
    focusFirstInput();
});

// Livewire更新後にも最初の入力欄にフォーカス（更新後は現在の入力欄の次にフォーカス）
if (typeof Livewire !== 'undefined') {
    Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
        succeed(({ snapshot, effect }) => {
            // DOM更新後に実行
            setTimeout(() => {
                const inputs = document.querySelectorAll('input[type="number"]:not([readonly])');
                if (inputs.length > 0 && !document.activeElement.matches('input[type="number"]')) {
                    inputs[0].focus();
                }
            }, 100);
        });
    });
}

function focusFirstInput() {
    const firstInput = document.querySelector('input[type="number"]:not([readonly])');
    if (firstInput) {
        firstInput.focus();
    }
}

// Enterキーで次の入力欄に移動
document.addEventListener('keydown', function(event) {
    if (event.key === 'Enter' && event.target.matches('input[type="number"]:not([readonly])')) {
        event.preventDefault();

        const inputs = Array.from(document.querySelectorAll('input[type="number"]:not([readonly])'));
        const currentIndex = inputs.indexOf(event.target);

        if (currentIndex > -1 && currentIndex < inputs.length - 1) {
            // 次の入力欄にフォーカス
            inputs[currentIndex + 1].focus();
            inputs[currentIndex + 1].select();
        } else if (currentIndex === inputs.length - 1) {
            // 最後の入力欄の場合、フォーカスを外す
            event.target.blur();
        }
    }
});
