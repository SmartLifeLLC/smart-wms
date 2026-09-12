<div class="space-y-5">
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">EOSデータ自動連携の全体仕様</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    有効なJX接続設定すべてを対象に、EOS受信、入荷予定更新、入荷確定、仕入データ自動生成を同じ実行単位で処理します。
                </p>
            </div>
            <div class="rounded-md bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                Queue実行
            </div>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">通常の実行タイミング</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                <li>毎日、設定された「毎日の受信時刻」に実行します。初期値は 22:00 です。</li>
                <li>曜日別の追加受信時刻は、各曜日につき最大2枠まで設定できます。</li>
                <li>初期設定では月曜 10:00、月曜 16:00 が追加実行枠です。月曜も通常の毎日実行は行います。</li>
                <li>画面右上の「今すぐ実行」は、有効なJX接続設定すべてでGetDocument受信し、今回受信したEOSログの取込までをQueueに投入します。</li>
            </ul>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">処理順序</h3>
            <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>有効なJX接続設定ごとに GetDocument を実行します。</li>
                <li>新規に受信した文書タイプ90のEOSデータを取込対象にします。</li>
                <li>EOS原本を保存し、入荷受信ファイル、伝票、明細へ変換します。</li>
                <li>受信伝票番号と送信時割当を照合し、入荷予定へ適用します。</li>
                <li>今回確定した入荷予定から、仕入データ自動生成対象だけを仕入キューへ登録します。</li>
                <li>最後に、発注系の古い未完了入荷予定を自動整理し、店間移動は予定日を1日以上過ぎている未完了入荷予定を全て完了にします。</li>
            </ol>
        </section>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">仕入データ自動生成の対象</h3>
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">対象になるもの</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>今回のEOS受信で入荷確定された入荷予定</li>
                    <li>EOS送信履歴または伝票番号割当が確認できる入荷予定</li>
                    <li>発注、手動、受信由来で、店間移動ではない入荷予定</li>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">対象外になるもの</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>仕入自動生成除外倉庫CDに設定された倉庫。初期値は91です。</li>
                    <li>EOS送信履歴が確認できない入荷予定</li>
                    <li>店間移動由来の入荷予定</li>
                    <li>すでに仕入キューIDが設定済みの入荷予定</li>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">仕入キューの単位</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>倉庫CD、仕入先CD、伝票番号、計上日、入荷日、買掛日でグルーピングします。</li>
                    <li>同じ伝票番号でも分納や入荷日違いは別の仕入キューになります。</li>
                    <li>1キューの明細は100件以下に分割します。</li>
                </ul>
            </div>
        </div>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">特別扱いと例外</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                <li>91番倉庫は入荷確定までは自動で行い、仕入データ生成は入荷完了画面から手動で行います。</li>
                <li>伝票番号割当が見つからないEOS受信伝票は自動確定せず、伝票番号不明データとして履歴に残します。</li>
                <li>商品が特定できない明細は入荷予定へ適用せず、取込エラーとして残します。</li>
                <li>単価差異確認用に、受信単価、送信時単価、マスタ単価、受信原本情報を保存します。</li>
            </ul>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">古い入荷予定の自動整理</h3>
            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                <li>発注系の未完了入荷予定は、発注日から設定された自動整理日数を過ぎると整理対象になります。初期値は14日です。</li>
                <li>対象になった発注系入荷予定は、入荷数量に関係なく入荷確定ではなく削除済みとして扱います。</li>
                <li>この自動整理はEOS送信済みに限定せず、対象発注日の未完了入荷予定全体に対して実行します。</li>
                <li>店間移動の入荷予定は、予定日を1日以上過ぎているものを全て入荷完了扱いにします。欠品数量は立てません。</li>
                <li>店間移動の数量変更や入荷確定は、酒丸勘定衆の倉庫移動メニューで行います。</li>
            </ul>
        </section>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">重複防止、ログ、エラー通知</h3>
        <div class="mt-3 grid gap-4 lg:grid-cols-3">
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">重複防止</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>定期実行は日付、スケジュールID、時刻から実行キーを作ります。</li>
                    <li>Queueジョブは実行キーまたは対象JXログIDで一意化します。</li>
                    <li>処理済みJXログ、取込済みファイル、仕入キュー済み入荷予定は再処理対象にしません。</li>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">ログ</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>実行単位で受信、取込、照合、適用、仕入生成、発注自動整理、移動完了の件数を保存します。</li>
                    <li>詳細ボタンから各ステップのログとエラー内容を確認できます。</li>
                    <li>JX受信履歴とEOS受信明細は別メニューにも原本履歴として残ります。</li>
                </ul>
            </div>
            <div>
                <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400">エラー時</h4>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                    <li>一部の伝票や明細だけ失敗した場合は一部失敗として完了し、成功分は保持します。</li>
                    <li>致命的な例外は実行ログに記録し、Laravelのエラーログに送ります。</li>
                    <li>LOG:ERROR の通知設定により、エラーはSlack通知対象になります。</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
        <h3 class="font-semibold">運用上の注意</h3>
        <ul class="mt-2 space-y-1.5">
            <li>仕入キュー作成後の入荷確定データは修正できません。修正が必要な場合は、仕入連携前に入荷完了画面で行います。</li>
            <li>仕入先CDは仕入データ連携時に発注先の通常仕入先を優先します。商品別仕入先が8007でも、発注先に通常仕入先がある場合は8007を送りません。</li>
            <li>既に基幹側で仕入キューが処理済みのデータは、queue再実行前に対象キューの内容を確認してください。</li>
        </ul>
    </section>
</div>
