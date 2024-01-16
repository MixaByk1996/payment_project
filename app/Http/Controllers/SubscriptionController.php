<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Plan as ModelsPlan;
use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Cashier\Subscription;
use Stripe\Plan;
use Stripe\SubscriptionItem;

class SubscriptionController extends Controller
{
    public function showPlanForm()
    {
        return view('stripe.plans.create');
    }
    public function savePlan(Request $request)
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $amount = ($request->amount * 100);

        try {
            $plan = Plan::create([
                'amount' => $amount,
                'currency' => $request->currency,
                'interval' => $request->billing_period,
                'interval_count' => $request->interval_count,
                'product' => [
                    'name' => $request->name
                ]
            ]);

            ModelsPlan::create([
                'plan_id' => $plan->id,
                'name' => $request->name,
                'price' => $plan->amount,
                'billing_method' => $plan->interval,
                'currency' => $plan->currency,
                'interval_count' => $plan->interval_count
            ]);

        }
        catch(Exception $ex){
            dd($ex->getMessage());
        }

        return "success";
    }
    public function allPlans(): Factory|View|Application
    {
        $plansAll = ModelsPlan::all();
//        $professional = ModelsPlan::where('name', 'professional')->first();
//        $enterprise = ModelsPlan::where('name', 'enterprise')->first();
        return view('stripe.plans', compact( 'plansAll'));
    }
    public function checkout($planId)
    {
        $plan = ModelsPlan::where('plan_id', $planId)->first();
        if(! $plan){
            return back()->withErrors([
                'message' => 'Unable to locate the plan'
            ]);
        }

        return view('stripe.plans.checkout', [
            'plan' => $plan,
            'intent' => auth()->user()->createSetupIntent(),
        ]);
    }
    public function processPlan(Request $request)
    {
        $user = auth()->user();
        $user->createOrGetStripeCustomer();
        $paymentMethod = null;
        $paymentMethod = $request->payment_method;
        if($paymentMethod != null){
            $paymentMethod = $user->addPaymentMethod($paymentMethod);
        }
        $plan = $request->plan_id;

        try {
            $planObj = ModelsPlan::query()->where('plan_id', $plan)->first();
            $obj = $user->newSubscription(
                $planObj->name, $plan
            )->create( $paymentMethod != null ? $paymentMethod->id: '');

            $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
            $pay = $stripe->subscriptions->retrieve($obj->stripe_id, []);
            Payment::query()->create([
               'object' => $pay->object,
               'customer' => auth()->user()->email,
               'latest_invoice' => $pay->latest_invoice,
               'price' => $pay->plan->amount / 100,
               'currency' => $pay->plan->currency
            ]);
            $pp = $stripe->subscriptions->update(
                $obj->stripe_id,
                [
                    'billing_cycle_anchor' => 'now',
                    'proration_behavior' => 'create_prorations',
                ]
            );
//            dd($pay);
//            dd(date('Y-m-d H:i:s',$pp->current_period_end));
        }
        catch(Exception $ex){
            return back()->withErrors([
                'error' => 'Unable to create subscription due to this issue '. $ex->getMessage()
            ]);
        }

        $request->session()->flash('alert-success', 'You are subscribed to this plan');
        return to_route('plans.checkout', $plan);
    }
    public function allSubscriptions(): Factory|View|Application
    {
        if (auth()->user()->onTrial('default')) {
            dd('trial');
        }
        $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));

        $subscriptions = collect(Subscription::where('user_id', auth()->id())->get())->map(function ($item) use ($stripe){
            $pay = $stripe->subscriptions->retrieve($item->stripe_id, []);
            $planItem = ModelsPlan::query()->where('plan_id', $item->stripe_price)->first();
            return [
              'name' => $item->name,
              'quantity' => $item->quantity,
              'next_pay_date' => date('Y-m-d H:i:s',$pay->current_period_end),
              'status' => $pay->status,
              'created_at' => $item->created_at,
                'price' => $planItem->price / 100,
              'ends_at' => $item->ends_at,
               'plan_name' => $planItem->name
            ];
        })->values();

        return view('stripe.subscriptions.index', compact('subscriptions'));
    }
    public function cancelSubscriptions(Request $request)
    {
        $subscriptionName = $request->subscriptionName;
        if($subscriptionName){
            $user = auth()->user();
            $user->subscription($subscriptionName)->cancel();
            return 'subsc is canceled';
        }
    }
    public function resumeSubscriptions(Request $request)
    {
        $user = auth()->user();
        $subscriptionName = $request->subscriptionName;
        if($subscriptionName){
            $user->subscription($subscriptionName)->resume();
            return 'subsc is resumed';
        }
    }
}
