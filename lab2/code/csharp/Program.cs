using EasyEncryption;
using EasyNetQ;
using Newtonsoft.Json.Linq;
using System;
using System.Collections.Concurrent;
using System.Diagnostics;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;

namespace csharp
{
    public class Program
    {
        public class CustomEasyNetQTypeNameSerializer : ITypeNameSerializer
        {
            private readonly ConcurrentDictionary<string, Type> deserializedTypes = new ConcurrentDictionary<string, Type>();

            public Type DeSerialize(string typeName)
            {
                return typeof(JObject);
                if (deserializedTypes.ContainsKey(typeName))
                    return deserializedTypes[typeName];
                else
                    throw new EasyNetQException("Blah blah blah, type does not exist.");
            }

            private readonly ConcurrentDictionary<Type, string> serializedTypes = new ConcurrentDictionary<Type, string>();

            public string Serialize(Type type)
            {
                return serializedTypes.GetOrAdd(type, t =>
                {
                    return t.FullName;
                });
            }
        }
        static async Task Main(string[] args)
        {
            CancellationTokenSource cts = new CancellationTokenSource();
            var bus = RabbitHutch.CreateBus("host=localhost", r =>
            {
                r.EnableLegacyTypeNaming();

                r.Register<ITypeNameSerializer, CustomEasyNetQTypeNameSerializer>();
            });


            var discoveryQueue = await bus.Advanced.QueueDeclareAsync($"{nameof(csharp)}.discovery.{DateTime.Now:O}", !false, false, false, cts.Token);
            await Task.Delay(1000);
            var exc = await bus.Advanced.ExchangeDeclareAsync("crypto-service-discovery", "fanout", false, false, cts.Token);

            await bus.Advanced.BindAsync(exc, discoveryQueue, "", cts.Token);


            var cancelQueue = await bus.Advanced.QueueDeclareAsync($"{nameof(csharp)}.cancel.{DateTime.Now:O}", !false, false, false, cts.Token);
            await Task.Delay(1000);
            var excx = await bus.Advanced.ExchangeDeclareAsync("crypto-cancel-requests", "fanout", false, false, cts.Token);
            await bus.Advanced.BindAsync(excx, cancelQueue, "", cts.Token);



            var responseQueue = await bus.Advanced.QueueDeclareAsync($"crypto-puzzle-responses", durable: false, false, !true, cts.Token);
            await Task.Delay(1000);
            var inquires = await bus.Advanced.QueueDeclareAsync($"crypto-puzzle-inquiries", durable: !false, false, false, cts.Token);
            await Task.Delay(1000);

            async Task send(string what)
            {
                Console.WriteLine($"Sending {what}");
                await bus.SendReceive.SendAsync(responseQueue.Name, what);
            }
            async Task calculate(string message, int difficulty, ulong from, ulong to, CancellationToken cts)
            {
                var r = new Stopwatch();
                r.Start();
                var desired = new string('0', difficulty);
                for (ulong i = Math.Min(to, from); i < Math.Max(to, from); i++)
                {
                    if (cts.IsCancellationRequested)
                    {
                        //await send("cancelled");
                        break;
                    }

                    string possible = SHA.ComputeSHA256Hash(message + i.ToString());
                    if (possible.Substring(0, difficulty) == desired)
                    {
                        r.Stop();
                        await send($"[time = {r.Elapsed}]" + message + " - " + i + " - " + possible);
                        break;
                    }
                }
            }
            CancellationTokenSource calculateCts = new CancellationTokenSource();


            await bus.SendReceive.ReceiveAsync<JObject>(cancelQueue.Name, (arg) =>
            {
                Console.WriteLine("Need to cancel");
                calculateCts.Cancel();

            }, cts.Token);
            await bus.SendReceive.ReceiveAsync<JObject>(inquires.Name, async (arg) =>
            {
                Console.WriteLine(arg.ToString());
                var oo = arg as dynamic;
                calculateCts = new CancellationTokenSource();
                await calculate(
                    (string)oo["string"],
                    int.Parse((string)oo["difficulty"]),
                    ulong.Parse(((string)oo["from"]).Split(".").First()),
                    ulong.Parse(((string)oo["to"]).Split(".").First()),
                    calculateCts.Token
                    );
                await Task.Delay(1000);
            }, cts.Token);
            await bus.SendReceive.ReceiveAsync<JObject>(discoveryQueue.Name, async (arg) =>
            {
                await send("discovered");
            }, cts.Token);

            Console.WriteLine("Started..");

            await Task.Delay(TimeSpan.FromMinutes(100));

        }
    }
}
