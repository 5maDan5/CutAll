# CutAll
 
このプラグインは、MineCraft JavaEditionのMOD『CutAll』を再現したものです。

## Install
 
1. このプラグインの.pharファイルをダウンロードする
2. サーバーのpluginフォルダへ入れる
3. サーバーを起動

## Requirement

PocketMine-MP 3.0.0 ~

## Config
 
細かな設定はplugin_data/CutAll/config.ymlを書き換えることで変更できます。

Options:
- enable.particle: ブロック破壊時のパーティクル

- limit.count: 一括破壊の最大数

- enable.wrong.wood.destroy: 違う木が連結していた際に破壊するか

- durability: 耐久値の減り方
  - 0: どれだけ壊しても１しか減らない
  - 1: 破壊した分耐久値が減るが、オーバーした分も破壊可
  - 2: 破壊した分耐久値が減り、オーバーした分は破壊不可

- toggle.command: CutAllの切り替えコマンド

- enable.leaves.destroy: 葉を破壊するか

- enable.under.destroy: 破壊した位置より下のブロックも破壊するか

- enable.drop.gather: ドロップアイテムをまとめるか

- start.mode: ログイン時にCutAllを有効化するか

- enable.during.creative: クリエイティブモード中もCutAllを有効化するか

- block.ids: 一括破壊するブロックIDを入力

- leaves.ids: 葉のブロックIDを入力

- item.ids: 一括破壊できるアイテムIDを入力

- leaves.range: 葉を破壊する範囲を設定
 
## Author
 
__5maDan5__
