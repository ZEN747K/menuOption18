<?php

namespace App\Http\Controllers;

use App\Events\OrderCreated;
use App\Http\Controllers\Controller;
use App\Models\Categories;
use App\Models\Config;
use App\Models\ConfigPromptpay;
use App\Models\LogStock;
use App\Models\Menu;
use App\Models\MenuOption;
use App\Models\MenuStock;
use App\Models\MenuTypeOption;
use App\Models\Orders;
use App\Models\OrdersDetails;
use App\Models\OrdersOption;
use App\Models\Promotion;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use PromptPayQR\Builder as PromptPayQRBuilder;

class Main extends Controller
{
    public function index(Request $request)
    {
        $table_id = $request->input('table');
        if ($table_id) {
            session(['table_id' => $table_id]);
        }
        $promotion = Promotion::where('is_status', 1)->get();
        $category  = Categories::has('menu')->with('files')->get();
        return view('users.main_page', compact('category', 'promotion'));
    }

    public function detail($id)
    {
        $item = [];
        $menu = Menu::where('categories_id', $id)
                    ->with('files')
                    ->orderBy('created_at', 'asc')
                    ->get();

        foreach ($menu as $key => $rs) {
            $item[$key] = [
                'id'         => $rs->id,
                'category_id'=> $rs->categories_id,
                'name'       => $rs->name,
                'detail'     => $rs->detail,
                'base_price' => $rs->base_price,
                'files'      => $rs->files,
            ];

            $typeOption = MenuTypeOption::where('menu_id', $rs->id)->get();
            if ($typeOption->count()) {
                foreach ($typeOption as $to) {
                    $optionItem = [];
                    $opt = MenuOption::where('menu_type_option_id', $to->id)->get();
                    foreach ($opt as $o) {
                        $optionItem[] = (object)[
                            'id'    => $o->id,
                            'name'  => $o->type,
                            'price' => $o->price,
                        ];
                    }
                    $item[$key]['option'][$to->name] = [
                        'is_selected' => $to->is_selected,
                        'amount'      => $to->amout,
                        'items'       => $optionItem,
                    ];
                }
            } else {
                $item[$key]['option'] = [];
            }
        }

        return view('users.detail_page', ['menu' => $item]);
    }

    public function order()
    {
        return view('users.list_page');
    }

    public function SendOrder(Request $request)
    {
        $data = ['status'=>false,'message'=>'สั่งออเดอร์ไม่สำเร็จ'];
        $orderData = $request->input('cart');
        $remark    = $request->input('remark');
        $items = []; $total = 0;

        foreach ($orderData as $order) {
            $options = !empty($order['options'])
                     ? array_column($order['options'], 'id')
                     : [];
            $items[] = [
                'menu_id'=> $order['id'],
                'quantity'=> $order['amount'],
                'price'   => $order['total_price'],
                'options' => $options,
            ];
            $total += $order['total_price'];
        }

        if ($items) {
            $order = new Orders();
            $order->table_id = session('table_id') ?: 1;
            $order->total    = $total;
            $order->remark   = $remark;
            $order->status   = 1;
            if ($order->save()) {
                foreach ($items as $rs) {
                    $detail = new OrdersDetails();
                    $detail->order_id = $order->id;
                    $detail->menu_id  = $rs['menu_id'];
                    $detail->quantity = $rs['quantity'];
                    $detail->price    = $rs['price'];
                    if ($detail->save()) {
                        foreach ($rs['options'] as $opt) {
                            // บันทึก OrdersOption
                            $orderOpt = new OrdersOption();
                            $orderOpt->order_detail_id = $detail->id;
                            $orderOpt->option_id       = $opt;
                            $orderOpt->save();

                            // ปรับสต็อก
                            MenuStock::where('menu_option_id', $opt)->get()->each(function($ms) use($order, $rs){
                                $stk = Stock::find($ms->stock_id);
                                $old = $stk->amount;
                                $stk->amount -= ($ms->amount * $rs['quantity']);
                                if ($stk->save()) {
                                    $log = new LogStock();
                                    $log->stock_id         = $ms->stock_id;
                                    $log->order_id         = $order->id;
                                    $log->menu_option_id   = $ms->menu_option_id;
                                    $log->old_amount       = $old;
                                    $log->amount           = $ms->amount * $rs['quantity'];
                                    $log->status           = 2;
                                    $log->save();
                                }
                            });
                        }
                    }
                }
                event(new OrderCreated(['📦 มีออเดอร์ใหม่']));
                $data = ['status'=>true,'message'=>'สั่งออเดอร์เรียบร้อยแล้ว'];
            }
        }

        return response()->json($data);
    }

    public function sendEmp()
    {
        event(new OrderCreated(['ลูกค้าเรียกจากโต้ะที่ '.session('table_id')]));
    }

    public function listorder()
{
    $orderlist = Orders::where('table_id', session('table_id'))
                       ->whereIn('status', [1,2])
                       ->get();

    $config = Config::first();
    $cp = ConfigPromptpay::where('config_id', $config->id)->first();

    // ตั้งค่า QR code
    $qr = '';
    if ($cp && $cp->promptpay) {
        // ถ้ามี PromptPay ให้สร้าง SVG QR
        $svg = PromptPayQRBuilder::staticMerchantPresentedQR($cp->promptpay)
                                 ->toSvgString();
        $qr = "<div class='row g-3 mb-3'>
                 <div class='col-md-12'>{$svg}</div>
               </div>";
    } elseif ($config->image_qr) {
        // ถ้าไม่มี PromptPay แต่มีรูป QR จาก config
        $url = url('storage/'.$config->image_qr);
        $qr = "<div class='row g-3 mb-3'>
                 <div class='col-md-12'>
                   <img width='100%' src='{$url}'>
                 </div>
               </div>";
    }

    return view('users.order', compact('orderlist', 'qr'));
}

    public function listorderDetails(Request $request)
    {
        $info = '';
        $groups = OrdersDetails::select('menu_id')
                    ->where('order_id', $request->id)
                    ->groupBy('menu_id')
                    ->get();

        foreach ($groups as $g) {
            $details = OrdersDetails::with('menu','option')
                        ->where('order_id', $request->id)
                        ->where('menu_id', $g->menu_id)
                        ->get();

            $name = optional($details->first()->menu)->name ?: 'ไม่พบชื่อเมนู';
            $info .= "<div class='mb-3'>";
            foreach ($details as $d) {
                $text  = $d->option ? '+ '.htmlspecialchars($d->option->type) : '';
                $price = number_format($d->quantity * $d->price, 2);
                $info .= "
                    <ul class='list-group mb-1 shadow-sm rounded'>
                      <li class='list-group-item d-flex justify-content-between'>
                        <div>
                          <span class='fw-bold'>".htmlspecialchars($name)."</span>
                          <div class='small text-secondary'>{$text}</div>
                        </div>
                        <div class='text-end'>
                          <div>จำนวน: {$d->quantity}</div>
                          <button class='btn btn-sm btn-primary'>{$price} บาท</button>
                        </div>
                      </li>
                    </ul>";
            }
            $info .= '</div>';
        }

        echo $info;
    }

    public function confirmPay(Request $request)
    {
        $data = ['status'=>false,'message'=>'สั่งออเดอร์ไม่สำเร็จ'];
        $request->validate([
            'silp' => 'required|image|mimes:jpeg,png|max:2048',
        ]);

        $orders = Orders::where('table_id', session('table_id'))
                        ->whereIn('status',[1,2])
                        ->get();

        foreach ($orders as $o) {
            $o->status = 4;
            if ($file = $request->file('silp')) {
                $filename = time().'_'.$file->getClientOriginalName();
                $o->image = $file->storeAs('image', $filename, 'public');
            }
            $o->save();
        }

        event(new OrderCreated(['📦 มีออเดอร์ใหม่']));
        $data = ['status'=>true,'message'=>'ชำระเงินเรียบร้อยแล้ว'];

        return response()->json($data);
    }
}
