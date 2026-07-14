<?php

namespace App\Observers;

use App\Models\Production;
use App\Models\Transfer;
use App\Models\Inventory;
use App\Enums\UserRoleType;
use App\Enums\InventoryType;
use App\Models\InventoryItem;
use App\Enums\TransferStatusType;
use Illuminate\Support\Facades\DB;

class ProductionObserver
{
    /**
     * Handle the Production "updated" event.
     * When approved_at is set, create a transfer automatically.
     */
    public function updated(Production $production)
    {
        // فقط اگر approved_at قبلاً null بوده و الان پُر شده
        if ($production->isDirty('approved_at') && $production->approved_at) {
            $this->createAutomaticTransfer($production);
        }
    }

    private function createAutomaticTransfer(Production $production)
    {
        $user = $production->user;
        $userRoles = $user->getRoleNames();

        if ($userRoles->contains(UserRoleType::FabricCutter->value)) {
            $this->handleFabricCutterTransfer($production);
        } elseif ($userRoles->contains(UserRoleType::ColoringWorker->value)) {
            $this->handleColoringWorkerTransfer($production);
        } elseif ($userRoles->contains(UserRoleType::MoldingWorker->value)) {
            $this->handleMoldingWorkerTransfer($production);
        }
    }

    private function handleFabricCutterTransfer(Production $production)
    {
        $fromInventory = $this->getOrCreateInventoryByType(InventoryType::CentralWarehouse->value);
        $toInventory = $this->getOrCreateInventoryByType(InventoryType::FabricCutter->value);

        // گرفتن نیازمندی‌های زیرمحصول
        $productPart = $production->productPart;
        $transferItems = [];

        foreach ($productPart->requirements as $requirement) {
            $requiredQuantity = $requirement->quantity * $production->bunch_count;

            $transferItems[] = [
                'item_id' => $requirement->required_item_id,
                'item_type' => $requirement->required_item_type,
                'color_id' => null, // نیازمندی‌ها رنگ ندارن
                'quantity' => $requiredQuantity,
                'notes' => "برای تولید {$productPart->name}"
            ];
        }

        // ایجاد حواله
        $transfer = Transfer::create([
            'from_user_id' => null,
            'to_user_id' => $production->user_id, // کاربر فرستنده
            'from_inventory_id' => $fromInventory->id,
            'to_inventory_id' => $toInventory->id,
            'transfer_date' => $production->production_date,
            'status' => TransferStatusType::Approved,
            'description' => "حواله خودکار: تولید برشکاری توسط {$production->user->firstname} {$production->user->lastname}",
        ]);

        foreach ($transferItems as $item) {
            $transfer->items()->create($item);
        }

        // اعمال تغییرات انبار
        $this->updateInventoryOnCreate($transfer);
    }

    private function handleColoringWorkerTransfer(Production $production)
    {
        $fromInventory = $this->getOrCreateInventoryByType(InventoryType::FabricCutter->value);
        $toInventory = $this->getOrCreateInventoryByType(InventoryType::ColoringWorker->value);

        $transferItem = [
            'item_id' => $production->product_part_id,
            'item_type' => 'App\Models\ProductPart',
            'color_id' => null, // قبل از رنگ شدن، رنگ نداره
            'quantity' => $production->bunch_count,
            'notes' => "برای رنگ‌آمیزی با رنگ {$production->color->name}"
        ];

        $transfer = Transfer::create([
            'from_user_id' => $production->user_id, // کاربر فرستنده
            'to_user_id' => null,
            'from_inventory_id' => $fromInventory->id,
            'to_inventory_id' => $toInventory->id,
            'transfer_date' => $production->production_date,
            'status' => TransferStatusType::Approved,
            'description' => "حواله خودکار: تولید رنگکاری توسط {$production->user->firstname} {$production->user->lastname}",
        ]);

        $transfer->items()->create($transferItem);

        $this->updateInventoryOnCreate($transfer);
    }

    private function handleMoldingWorkerTransfer(Production $production)
    {
        $fromInventory = $this->getOrCreateInventoryByType(InventoryType::ColoringWorker->value);
        $toInventory = $this->getOrCreateInventoryByType(InventoryType::MoldingWorker->value);

        $transferItem = [
            'item_id' => $production->product_part_id,
            'item_type' => 'App\Models\ProductPart',
            'color_id' => $production->color_id, // الان رنگ داره
            'quantity' => $production->bunch_count,
            'notes' => "برای اتو زدن"
        ];

        $transfer = Transfer::create([
            'from_user_id' => $production->user_id, // کاربر فرستنده
            'to_user_id' => null,
            'from_inventory_id' => $fromInventory->id,
            'to_inventory_id' => $toInventory->id,
            'transfer_date' => $production->production_date,
            'status' => TransferStatusType::Approved,
            'description' => "حواله خودکار: تولید اتوکاری توسط {$production->user->firstname} {$production->user->lastname}",
        ]);

        $transfer->items()->create($transferItem);

        $this->updateInventoryOnCreate($transfer);
    }

    private function getOrCreateInventoryByType(string $type)
    {
        $name = match($type) {
            'fabric_cutter' => 'انبار برشکاری',
            'coloring_worker' => 'انبار رنگکاری',
            'molding_worker' => 'انبار اتوکاری',
            'central_warehouse' => 'انبار مرکزی',
            default => ucfirst(str_replace('_', ' ', $type))
        };

        return Inventory::firstOrCreate(
            ['type' => $type],
            [
                'name' => $name,
                'description' => "انبار عمومی - {$type}",
                'user_id' => null
            ]
        );
    }

    private function updateInventoryOnCreate(Transfer $transfer): void
    {
        foreach ($transfer->items as $item) {
            $strategy = \App\Services\TransferItemStrategy\StrategyResolver::resolve($item);

            // From inventory
            if ($transfer->from_inventory_id) {
                $fromItem = InventoryItem::firstOrCreate(
                    [
                        'inventory_id' => $transfer->from_inventory_id,
                        'item_id' => $item->item_id,
                        'item_type' => $item->item_type,
                        'color_id' => $item->color_id,
                    ], [
                        'unit' => $item->item?->unit_small ?? 'don'
                    ]
                );

                if (!$fromItem->exists) {
                    $fromItem->save();
                }

                $strategy->handleOutgoing($fromItem, $item->quantity);
            }

            // To inventory
            if ($transfer->to_inventory_id) {
                $toItem = InventoryItem::firstOrCreate(
                    [
                        'inventory_id' => $transfer->to_inventory_id,
                        'item_id' => $item->item_id,
                        'item_type' => $item->item_type,
                        'color_id' => $item->color_id,
                    ], [
                        'unit' => $item->item?->unit_small ?? 'don'
                    ]
                );

                if (!$toItem->exists) {
                    $toItem->save();
                }

                $strategy->handleIncoming($toItem, $item->quantity);
            }
        }
    }
}
