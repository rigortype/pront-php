# Project Hand-off: PHP Attribute/PHPDoc-Driven PBT Library

本ドキュメントは、PHPの実装型シグネチャおよびPHPDoc（`@phpstan-param` / `@phpstan-return`）を単一の信頼できる情報源（Single Source of Truth）としてGeneratorを自動合成し、Attributesによって代数的性質・契約・変成関係を宣言的にテストする次世代Property-Based Testing（PBT）ライブラリの実装仕様書です。

---

## 1. コア設計思想 & アーキテクチャ原則

1. **Zero-Boilerplate by Default**
テストコードを別ファイルに書くことなく、実装の型定義とPHPDoc篩型（Refinement Types）からGeneratorとオラクル（戻り値の型検証）を完全自動合成する。
2. **Internal Shrinking（Choice Sequence方式）**
古典的QuickCheckの「型ごとのカスタムShrinker定義」を完全に廃止。乱数ドロー列（ChoiceBuffer）そのものを縮小し、Generatorを再実行して値を復元する方式を採用。依存生成や複雑なドメインオブジェクトでも不変条件を破壊せずに最小反例へ収束させる。
3. **Metamorphic Testingのファーストクラス化**
単一実行のアサーションだけでなく、複数回実行の関係性（単調性、べき等性、バッチ準同型性、参照実装比較）をAttributesで直接宣言可能にする。
4. **型安全性と依存解決**
引数間の依存関係（DAG）をトポロジカルソートで自動解決し、モナド的 `flatMap` なしで棄却率0%の依存生成を実現する。高階関数（`callable`）には決定論的メモ化関数（`CoArbitrary`）を自動注入する。

---

## 2. 推奨ディレクトリ構成 & 依存関係

### composer.json 推奨依存

* `php`: `^8.2`
* `phpstan/phpdoc-parser`: `^1.25`（PHPDoc型のパース）
* `nikic/php-parser`: `^5.0`（式解析・依存パラメータ抽出・静的リフレクション補助）
* `symfony/expression-language`: `^7.0`（軽量な事後条件式評価器）

```text
src/
├── Attribute/                # 公開Attributes
│   ├── Verify.php            # メソッド検証エントリポイント
│   ├── Ensures.php           # 汎用事後条件
│   ├── Assume.php            # 事前条件フィルタ
│   ├── Cover.php             # 分布・被覆率保証
│   ├── Algebraic/            # 代数的ショートハンド (Idempotent, Commutative, etc.)
│   ├── Metamorphic/          # 変成ショートハンド (Monotonic, BatchHomomorphic, etc.)
│   ├── Collection/           # 配列・コレクション (PermutationOf, PreservesKeys, etc.)
│   ├── Safety/               # 防御的制約 (Immutable, OnlyThrows, NeverThrows, ClassInvariant)
│   ├── Gen/                  # 入力修飾子 (Sorted, Regex, IntRange, ElementOf, etc.)
│   └── Stateful/             # 状態遷移 (StatefulVerify, StateCommand, ShadowModel)
├── Core/
│   ├── ChoiceBuffer.php      # 乱数ドロー列テープ (記録・リプレイ)
│   ├── ChoiceShrinker.php    # テープ縮小エンジン (Block Deletion / Minimizing)
│   └── ExecutionContext.php  # 引数マップ・$return・$this・$old のスコープ管理
├── Generator/
│   ├── GeneratorInterface.php
│   ├── TypeAstToGenerator.php # phpstan/phpdoc-parser AST -> Generator 変換器
│   ├── DependentGraph.php    # 引数間DAG解析・トポロジカルソーター
│   ├── CoArbitrary/          # callable 自動合成エンジン
│   │   └── GeneratedCallable.php
│   └── Primitives/           # プリミティブ・コレクション・文字コード生成
├── Evaluator/
│   ├── ExpressionEvaluator.php # 式評価 (Symfony ExpressionLanguage ラッパー)
│   └── AstDependencyInspector.php # 式から参照変数を抽出するパーサ
├── Stateful/
│   ├── StateMachineRunner.php # コマンド列生成・実行ループ
│   └── CommandStep.php       # 1ステップのコマンドと引数の定義
└── Runner/
    ├── AttributeCollector.php # クラス/メソッド走査
    ├── PropertyRunner.php    # 単一プロパティ実行制御
    ├── Counterexample.php    # 最小反例データモデル
    └── Reporter/             # CLI整形出力 (テーブル、反例、再現コード)

```

---

## 3. Attribute API カタログ

### 3.1 実行制御・基本契約

* `#[Verify(int $iterations = 100, int $maxShrinkSteps = 1000, ?int $seed = null)]`
* テスト対象メソッドに付与。ランナーの検出対象となる。


* `#[Ensures(string|callable $condition, string $message = '')]`
* 事後条件。式文字列内では `$return`, `$this`, 仮引数名（`$price` 等）がバインドされる。


* `#[Assume(string|callable $condition)]`
* 生成値の事前条件フィルタ（棄却サンプリング）。一定回数以上連続棄却された場合は Generator 設定エラーとして落とす。



### 3.2 代数的性質 & 変成ショートハンド（PropertyAssertionAttribute）

すべて `Pbt\Contract\PropertyAssertionAttribute` を実装し、ランナー側で実行呼び出しをラップ・展開する。

* `#[Idempotent]`：$f(f(x)) === f(x)$
* `#[InverseOf(callable $decoder)]`：$decoder(f(x)) === x$
* `#[EquivalentTo(callable $oracle)]`：$f(x) === oracle(x)$
* `#[Commutative]`：$f(a, b) === f(b, a)$
* `#[Associative]`：$f(f(a, b), c) === f(a, f(b, c))$
* `#[Identity(mixed $identityElement)]`：$f(a, e) === a$
* `#[Monotonic(string $param, Direction::Increasing|Direction::Decreasing)]`
* $x_1 \le x_2 \implies f(x_1) \le f(x_2)$ を検証。引数を2セット生成して評価。


* `#[BatchHomomorphic]`
* $f([...a, ...b]) === [...f(a), ...f(b)]$



### 3.3 コレクション & 防御的制約

* `#[PermutationOf(string $targetParam)]`：多重集合として一致（ソートの検証）。
* `#[Ordered(Order::Asc|Order::Desc, ?string $byKey = null)]`：結果が整列済みであること。
* `#[SubsetOf(string $targetParam)]`：フィルタ結果が元の部分集合であること。
* `#[PreservesCount(string $targetParam)]` / `#[PreservesKeys(string $targetParam)]`
* `#[Immutable]`：メソッド実行前後でレシーバ `$this` の内部状態がディープイコールであること。
* `#[OnlyThrows(string ...$allowedExceptions)]` / `#[NeverThrows]`
* `#[ClassInvariant(string|callable $condition)]`：全操作後に常に成立すべきクラス不変条件。

### 3.4 入力生成・パラメータ修飾（Parameter 付与）

* `#[Gen\IntRange(int|string $min, int|string $max)]`：文字列で `'count($items)'` 等の動的バインド可。
* `#[Gen\Sorted]`：生成配列を強制ソートして注入。
* `#[Gen\ElementOf(string $targetParam, int $weight = 100)]`：先行配列からランダム抽出。
* `#[Gen\Regex(string $pattern)]`：正規表現に適合する文字列を生成。
* `#[HostileStrings]`：サロゲートペア、NULLバイト、制御文字、異体字、空文字を優先注入。

### 3.5 分布保証 & ステートフルPBT

* `#[Cover(string $condition, float $minPercent, string $label = '')]`
* 全試行中の真となった割合を記録し、下回った場合は Vacuous Truth（空虚な真）としてテスト失敗。


* `#[StatefulVerify(int $maxSteps = 50, int $iterations = 100, ?string $shadowModel = null)]`
* クラスに付与。状態遷移PBTを有効化。


* `#[StateCommand(int $weight = 1)]` / `#[Precondition(string $expr)]` / `#[Postcondition(string $expr)]`
* 状態遷移コマンドメソッドの定義。式内では直前状態 `$old` が使用可能。



---

## 4. コアサブシステム実装仕様

### 4.1 ChoiceBuffer & Internal Shrinking（最重要コア）

Generator は直接乱数を生成せず、すべて `ChoiceBuffer::drawInt($min, $max)` を経由する。

```php
namespace Pbt\Core;

final class ChoiceBuffer
{
    private array $choices = [];
    private int $cursor = 0;

    public function __construct(private readonly ?array $replay = null) {}

    public function drawInt(int $min, int $max): int
    {
        $span = $max - $min;
        if ($this->replay !== null) {
            if ($this->cursor >= count($this->replay)) {
                throw new BufferOverrunException();
            }
            $raw = min($this->replay[$this->cursor++], $span);
        } else {
            $raw = random_int(0, $span);
        }
        $this->choices[] = $raw;
        return $min + $raw;
    }

    public function getChoices(): array { return $this->choices; }
}

```

#### ChoiceShrinker アルゴリズム

1. **Pass 1: Block Deletion**
* テープ長 $N$ に対し、ブロックサイズ $K \in \{8, 4, 2, 1\}$ で連続する $K$ 要素の削除を試行。
* テストが再失敗（バグ再現）したら候補を新たなベストとし、インデックスをリセット。


2. **Pass 2: Value Minimizing**
* 各スロットの値を二分探索で `0` に向かって縮小（$0 \le mid < current$）。
* テストが再失敗する最小値を探索。



### 4.2 PHPDoc型ASTからGeneratorへの導出（`TypeAstToGenerator`）

`phpstan/phpdoc-parser` の `TypeNode` を再帰走査して Generator を構築する。

* `IntegerRangeTypeNode($min, $max)` $\to$ `drawInt($min, $max)`
* `UnionTypeNode([$t1, $t2])` $\to$ インデックスをドローし、選択された型の Generator を実行。
* `ArrayShapeNode` $\to$ 各フィールドの Generator を順次実行して連想配列を構築。
* `GenericTypeNode('list', [$innerType])` $\to$ 長さ $L$ をドローし、$L$ 回 `$innerType` を生成。
* `CallableTypeNode` $\to$ `GeneratedCallable` をインスタンス化して注入。

### 4.3 依存生成DAG（`DependentGraph`）

パラメータ属性内の式文字列を走査し、参照されている変数名（`$paramName`）を特定する。

1. 引数間の有向グラフを構築（循環参照時は例外）。
2. トポロジカルソートを実行し、依存元の引数を先に生成。
3. 生成済みの引数マップ（`['text' => 'hello']`）をコンテキストとして評価器に渡し、後続引数の `min` / `max` などの制約を解決して生成する。

### 4.4 高階関数の決定論的合成（`GeneratedCallable`）

```php
namespace Pbt\Generator\CoArbitrary;

use Pbt\Core\ChoiceBuffer;
use Pbt\Generator\GeneratorInterface;

final class GeneratedCallable
{
    private array $memo = [];

    public function __construct(
        private readonly int $seed,
        private readonly GeneratorInterface $returnGenerator
    ) {}

    public function __invoke(mixed ...$args): mixed
    {
        $key = serialize($args);
        if (!array_key_exists($key, $this->memo)) {
            // 引数のハッシュ値とベースSeedを合成した決定論的テープを初期化
            $subSeed = $this->seed ^ crc32($key);
            mt_srand($subSeed);
            $buf = new ChoiceBuffer(); // または決定論的PRNGから供給
            $this->memo[$key] = $this->returnGenerator->generate($buf);
        }
        return $this->memo[$key];
    }

    public function dumpHistory(): array
    {
        $dump = [];
        foreach ($this->memo as $k => $v) {
            $dump[] = ['args' => unserialize($k), 'return' => $v];
        }
        return $dump;
    }
}

```

### 4.5 ステートフルPBTランナー（`StateMachineRunner`）

1. クラスに付与された `#[StatefulVerify]` を検出し、コンストラクタ引数を生成してSUT（System Under Test）およびシャドウモデルを初期化。
2. 各ステップで `#[StateCommand]` メソッド群から実行可能なもの（`#[Precondition]` を満たすもの）をフィルタ。
3. 選択確率（`weight`）に基づいて1つのコマンドを選び、そのメソッドの引数を生成。
4. SUT実行前のシャローコピー `$old = clone $sut` を作成。
5. メソッドを実行し、シャドウモデルが存在する場合は同期実行して戻り値の一致を検証。
6. `#[Postcondition]` およびクラス全体の `#[ClassInvariant]` を検証。
7. 失敗時：**ChoiceBuffer（選んだコマンドのインデックスと各引数のドロー列が平坦に並んだテープ）** をそのまま `ChoiceShrinker` に渡し、最短かつ引数が最小の再現コマンド列に縮小。

---

## 5. 実装フェーズ（マイルストーン）

* **Phase 1: 最小コアエンジン (MVP)**
* `ChoiceBuffer` および `ChoiceShrinker` の実装。
* プリミティブ型（int, string, bool, array）の Generator。
* `#[Verify]` と `#[Ensures]` によるメソッド実行と反例 Shrinking の開通。


* **Phase 2: PHPDoc型システム連携**
* `phpstan/phpdoc-parser` を統合し、`int<min, max>`, `list<T>`, `array{...}`, `Union` からの自動生成を実装。
* `@phpstan-return` に基づく暗黙の型検証オラクル。


* **Phase 3: 代数的・変成ショートハンド & 依存生成**
* `#[Idempotent]`, `#[Monotonic]`, `#[PermutationOf]` などの展開ロジック。
* 引数依存DAGのトポロジカルソートと動的境界解決。


* **Phase 4: CoArbitrary & 分布検証**
* `callable` 引数の自動生成と呼び出しダンプ。
* `#[Cover]` による Vacuous Truth ガード。


* **Phase 5: Stateful PBT & CLI/PHPUnit ブリッジ**
* `#[StatefulVerify]` のコマンド列生成・実行・Shrink。
* 失敗Seedの永続化ファイル（`.pbt_replay`）とPHPUnitアサーションブリッジ。