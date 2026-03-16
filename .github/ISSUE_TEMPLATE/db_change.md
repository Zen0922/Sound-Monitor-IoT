---
name: DB変更
about: テーブル定義やインデックス変更などDB関連の変更を記録する
title: "[DB] "
labels: ["type:design", "area:db"]
assignees: []
---

## 概要
変更内容を簡潔に記載してください。

## 背景
なぜ変更が必要か。

## 対象
- table
- index
- view
- migration
- data cleanup

## 対象オブジェクト
例
- sensor_device
- sensor_value
- detected_sensor_device

## 現状
現在の問題点を記載。

## 変更内容
予定している変更内容。

## 変更SQL案

ここにSQLを書く

## 影響範囲
- gateway Python
- web PHP
- DB
- systemd/service
- config

## データ移行
- 不要
- 必要
- 要検討

## ロールバック方法
失敗時の戻し方。

## 確認方法
- CREATE TABLE確認
- EXPLAIN確認
- アプリ動作確認
- データ件数確認

## 完了条件
Issueを閉じる条件。